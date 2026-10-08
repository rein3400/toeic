<?php
/**
 * TOEIC answer-save recovery E2E fixture (synthetic, isolated).
 *
 * CLI-only operations:
 *   1. CLI:   php toeic_save_recovery_fixture.php --action=<init|add-session|db-get|set-timer|hash-sources|ping>
 *
 * Isolation contract (refuses to write otherwise):
 *   - MariaDB @@port must equal explicit TOEIC_E2E_DB_PORT (isolated loopback).
 *   - MariaDB @@datadir must equal explicit TOEIC_E2E_DATADIR exactly.
 *
 * BOOTSTRAP AUTH BOUNDARY: this fixture does NOT QA login.php / credentials /
 * vouchers / proctor camera flow. It bootstraps an already-authenticated
 * student PHP session (DB-backed `sessions` row + PHPSESSID cookie) and a
 * synthetic full-mode TOEIC attempt. Anything about "user X logged in" is
 * therefore out of scope by construction; see toeic-save-recovery/README.md.
 *
 * Synthetic data is labeled SYNTH_* and lives in a throwaway database
 * `toeic_saverec_<rand>` dropped by the harness after the run.
 */
declare(strict_types=1);
date_default_timezone_set('UTC');

define('SAVEREC_DB_PORT', (int)(getenv('TOEIC_E2E_DB_PORT') ?: 23471));
define('SAVEREC_DATADIR', rtrim(str_replace('\\', '/', (string)getenv('TOEIC_E2E_DATADIR')), '/'));
const SAVEREC_USER_ID = 424242;
const SAVEREC_ROLE = 'student';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }

/* ---------------------------------------------------------------- helpers */
/** Isolated fixture operation: fail. */
function saverec_fail(string $msg): never {
    fwrite(STDERR, 'saverec: ' . $msg . PHP_EOL);
    exit(1);
}

/** Isolated fixture operation: db. */
function saverec_db(array $opts): mysqli {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = $opts['db'] ?? '';
    if (!preg_match('/^toeic_saverec_[0-9a-f]{8}$/D', (string)$db) && $db !== '') {
        saverec_fail('unsafe database name');
    }
    if (SAVEREC_DB_PORT < 1024 || SAVEREC_DB_PORT > 65535 || in_array(SAVEREC_DB_PORT, [3306, 13361, 18931], true) || SAVEREC_DATADIR === '') { saverec_fail('explicit isolated port/datadir required'); }
    $c = new mysqli('127.0.0.1', 'root', '', '', SAVEREC_DB_PORT);
    saverec_assert_isolated($c);
    if ($db !== '') {
        $c->select_db($db);
    }
    $c->set_charset('utf8mb4');
    $c->query("SET time_zone = '+00:00'");
    return $c;
}

/** CLI-only session bootstrap: encode/decode need an active session handle. */
/** Isolated fixture operation: session begin. */
function saverec_session_begin(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/** Refuse every server except our owned isolated MariaDB. No writes before this. */
/** Isolated fixture operation: assert isolated. */
function saverec_assert_isolated(mysqli $c): void {
    $row = $c->query('SELECT @@datadir AS datadir, @@port AS port')->fetch_assoc();
    $datadir = str_replace('\\', '/', (string)($row['datadir'] ?? ''));
    $port = (int)($row['port'] ?? 0);
    if ($port !== SAVEREC_DB_PORT || rtrim($datadir, '/') !== SAVEREC_DATADIR) {
        saverec_fail(sprintf('REFUSING non-isolated server (port=%d datadir=%s)', $port, $datadir));
    }
}

/** Isolated fixture operation: schema. */
function saverec_schema(mysqli $c): void {
    $stmts = [
        "CREATE TABLE IF NOT EXISTS sessions (id VARCHAR(128) NOT NULL PRIMARY KEY, access INT(10) UNSIGNED, data TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value LONGTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS toeic_photos (id_photo INT AUTO_INCREMENT PRIMARY KEY, file_path VARCHAR(255) NOT NULL, description TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS toeic_audio (id_audio INT AUTO_INCREMENT PRIMARY KEY, judul VARCHAR(255) NOT NULL, part VARCHAR(2) NOT NULL, file_path VARCHAR(255) NULL, transcript LONGTEXT NULL, context TEXT NULL, id_photo INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS toeic_teks (id_teks INT AUTO_INCREMENT PRIMARY KEY, judul VARCHAR(255) NOT NULL, part VARCHAR(2) NOT NULL, text_type VARCHAR(50) NULL, isi_teks LONGTEXT NOT NULL, isi_teks_2 LONGTEXT NULL, isi_teks_3 LONGTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS toeic_soal_listening (id_soal INT AUTO_INCREMENT PRIMARY KEY, part VARCHAR(2) NOT NULL, nomor_soal INT NOT NULL, pertanyaan TEXT NOT NULL, opsi_a TEXT NULL, opsi_b TEXT NULL, opsi_c TEXT NULL, opsi_d TEXT NULL, jawaban_benar VARCHAR(20) NOT NULL, explanation LONGTEXT NULL, id_audio INT NULL, question_type VARCHAR(50) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS toeic_soal_reading (id_soal INT AUTO_INCREMENT PRIMARY KEY, part VARCHAR(2) NOT NULL, nomor_soal INT NOT NULL, pertanyaan TEXT NOT NULL, opsi_a TEXT NULL, opsi_b TEXT NULL, opsi_c TEXT NULL, opsi_d TEXT NULL, jawaban_benar VARCHAR(20) NOT NULL, explanation LONGTEXT NULL, id_teks INT NULL, question_type VARCHAR(50) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS toeic_test_sessions (id INT AUTO_INCREMENT PRIMARY KEY, test_session VARCHAR(191) NOT NULL UNIQUE, user_id INT NOT NULL, current_section VARCHAR(30) NOT NULL DEFAULT 'listening', status VARCHAR(30) NOT NULL DEFAULT 'active', practice_mode TINYINT(1) NOT NULL DEFAULT 0, target_part VARCHAR(2) NULL, checkout_source VARCHAR(40) NULL, checkout_reference VARCHAR(120) NULL, listening_raw INT NULL, listening_scaled INT NULL, reading_raw INT NULL, reading_scaled INT NULL, total_score INT NULL, cefr_level VARCHAR(20) NULL, fallback_count INT NOT NULL DEFAULT 0, short_filled_count INT NOT NULL DEFAULT 0, started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, completed_at TIMESTAMP NULL DEFAULT NULL, INDEX idx_user_status (user_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS toeic_test_questions (id INT AUTO_INCREMENT PRIMARY KEY, test_session VARCHAR(191) NOT NULL, user_id INT NOT NULL, question_id INT NOT NULL, question_type VARCHAR(80) NULL, section VARCHAR(30) NOT NULL, part VARCHAR(2) NOT NULL, question_order INT NOT NULL, stimulus_group_id VARCHAR(100) NULL, group_order INT NULL DEFAULT 0, user_answer VARCHAR(10) NULL, is_correct DECIMAL(5,2) NULL, answered_at DATETIME NULL, snap_jawaban_benar CHAR(1) NULL DEFAULT NULL, snap_pertanyaan LONGTEXT NULL, snap_opsi_a TEXT NULL, snap_opsi_b TEXT NULL, snap_opsi_c TEXT NULL, snap_opsi_d TEXT NULL, original_question_id INT NULL, UNIQUE KEY uniq_session_question (test_session, section, question_id), INDEX idx_session_section_order (test_session, section, question_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS toeic_test_results (id INT AUTO_INCREMENT PRIMARY KEY, test_session VARCHAR(191) NOT NULL UNIQUE, user_id INT NOT NULL, listening_raw INT NOT NULL DEFAULT 0, listening_scaled INT NOT NULL DEFAULT 5, reading_raw INT NOT NULL DEFAULT 0, reading_scaled INT NOT NULL DEFAULT 5, total_score INT NOT NULL DEFAULT 10, cefr_level VARCHAR(20) NULL, completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS toeic_score_conversion (id INT AUTO_INCREMENT PRIMARY KEY, section VARCHAR(30) NOT NULL, raw_score INT NOT NULL, scaled_score INT NOT NULL, UNIQUE KEY uniq_section_raw (section, raw_score)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS proctoring_sessions (id INT AUTO_INCREMENT PRIMARY KEY, test_session VARCHAR(191) NOT NULL, user_id INT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'active') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($stmts as $s) {
        $c->query($s);
    }
    foreach (['listening', 'reading'] as $section) {
        for ($raw = 0; $raw <= 4; $raw++) {
            $scaled = 5 + $raw * 5;
            $stmt = $c->prepare('INSERT IGNORE INTO toeic_score_conversion (section, raw_score, scaled_score) VALUES (?, ?, ?)');
            $stmt->bind_param('sii', $section, $raw, $scaled); $stmt->execute(); $stmt->close();
        }
    }
    $c->query("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('website_title', 'SYNTH TOEIC E2E')");
}

/** Seed synthetic bank (Part 2 listening + Part 5 reading; NO Part 1 so the
 *  photo-availability guard stays out of the save-path under test) and one
 *  full-mode attempt. Returns [testSession, listeningQids, readingQids]. */
/** Isolated fixture operation: seed. */
function saverec_seed(mysqli $c): array {
    $lQids = [];
    $answersL = ['B', 'A', 'C', 'C'];
    for ($i = 1; $i <= 4; $i++) {
        $correct = $answersL[$i - 1];
        $stmt = $c->prepare("INSERT INTO toeic_soal_listening (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, question_type) VALUES ('2', ?, ?, 'SYNTH option A', 'SYNTH option B', 'SYNTH option C', 'SYNTH option D', ?, 'SYNTH fixture explanation', 'fixture_part_2')");
        $stem = 'SYNTH listening prompt ' . $i;
        $stmt->bind_param('iss', $i, $stem, $correct);
        $stmt->execute();
        $lQids[] = (int)$c->insert_id;
        $stmt->close();
    }
    $rQids = [];
    $answersR = ['C', 'B', 'A', 'D'];
    for ($i = 1; $i <= 4; $i++) {
        $correct = $answersR[$i - 1];
        $stmt = $c->prepare("INSERT INTO toeic_soal_reading (part, nomor_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar, explanation, question_type) VALUES ('5', ?, ?, 'SYNTH option A', 'SYNTH option B', 'SYNTH option C', 'SYNTH option D', ?, 'SYNTH fixture explanation', 'fixture_part_5')");
        $stem = 'SYNTH reading stem ' . $i . ': the report ___ yesterday.';
        $stmt->bind_param('iss', $i, $stem, $correct);
        $stmt->execute();
        $rQids[] = (int)$c->insert_id;
        $stmt->close();
    }
    $testSession = 'toeic_e2e_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $stmt = $c->prepare("INSERT INTO toeic_test_sessions (test_session, user_id, current_section, status, practice_mode) VALUES (?, ?, 'listening', 'active', 0)");
    $uid = SAVEREC_USER_ID;
    $stmt->bind_param('si', $testSession, $uid);
    $stmt->execute();
    $stmt->close();
    $order = 1;
    foreach ($lQids as $idx => $qid) {
        saverec_assign($c, $testSession, $qid, 'listening', '2', $order++, $answersL[$idx]);
    }
    $order = 1;
    foreach ($rQids as $idx => $qid) {
        saverec_assign($c, $testSession, $qid, 'reading', '5', $order++, $answersR[$idx]);
    }
    return [$testSession, $lQids, $rQids];
}

/** Isolated fixture operation: assign. */
function saverec_assign(mysqli $c, string $testSession, int $qid, string $section, string $part, int $order, string $correct): void {
    $uid = SAVEREC_USER_ID;
    $stmt = $c->prepare("INSERT INTO toeic_test_questions (test_session, user_id, question_id, question_type, section, part, question_order, stimulus_group_id, group_order, snap_jawaban_benar, snap_pertanyaan, snap_opsi_a, snap_opsi_b, snap_opsi_c, snap_opsi_d, original_question_id) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 0, ?, 'SYNTH stem', 'SYNTH A', 'SYNTH B', 'SYNTH C', 'SYNTH D', ?)");
    $qt = 'fixture_part_' . $part;
    $stmt->bind_param('siisssisi', $testSession, $uid, $qid, $qt, $section, $part, $order, $correct, $qid);
    $stmt->execute();
    $stmt->close();
}

/** Bootstrap an authenticated student session row. Returns [sid, csrf]. */
/** Isolated fixture operation: make session. */
function saverec_make_session(mysqli $c, string $testSession): array {
    saverec_session_begin();
    $sid = 'e2e' . bin2hex(random_bytes(12));
    $csrf = bin2hex(random_bytes(32));
    $_SESSION = [];
    $_SESSION['user_id'] = SAVEREC_USER_ID;
    $_SESSION['role'] = SAVEREC_ROLE;
    $_SESSION['csrf_token'] = $csrf;
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SESSION['toeic_test_session'] = $testSession;
    $_SESSION['test_session'] = $testSession;
    $_SESSION['test_format'] = 'toeic';
    $_SESSION['current_section'] = 'listening';
    $_SESSION['section_start_time'] = time();
    $_SESSION['toeic_section_start_times'] = [$testSession . ':listening' => time()];
    $enc = session_encode();
    $stmt = $c->prepare('REPLACE INTO sessions (id, access, data) VALUES (?, ?, ?)');
    $access = time();
    $stmt->bind_param('sis', $sid, $access, $enc);
    $stmt->execute();
    $stmt->close();
    return [$sid, $csrf];
}

/** Isolated fixture operation: load session. */
function saverec_load_session(mysqli $c, string $sid): array {
    saverec_session_begin();
    $stmt = $c->prepare('SELECT data FROM sessions WHERE id = ?');
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        saverec_fail('session not found: ' . $sid);
    }
    $_SESSION = [];
    if (!session_decode((string)$row['data'])) {
        saverec_fail('session_decode failed');
    }
    return $_SESSION;
}

/** Isolated fixture operation: save session. */
function saverec_save_session(mysqli $c, string $sid): void {
    $enc = session_encode();
    $stmt = $c->prepare('REPLACE INTO sessions (id, access, data) VALUES (?, ?, ?)');
    $access = time();
    $stmt->bind_param('sis', $sid, $access, $enc);
    $stmt->execute();
    $stmt->close();
}

/** Isolated fixture operation: db dump. */
function saverec_db_dump(mysqli $c, string $testSession): array {
    $out = ['session' => null, 'questions' => [], 'results' => null];
    $stmt = $c->prepare('SELECT test_session, status, current_section, practice_mode, completed_at, listening_raw, reading_raw, listening_scaled, reading_scaled, total_score FROM toeic_test_sessions WHERE test_session = ?');
    $stmt->bind_param('s', $testSession);
    $stmt->execute();
    $out['session'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stmt = $c->prepare('SELECT section, question_order, question_id, user_answer, is_correct FROM toeic_test_questions WHERE test_session = ? ORDER BY section, question_order');
    $stmt->bind_param('s', $testSession);
    $stmt->execute();
    $out['questions'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $c->prepare('SELECT test_session, total_score, cefr_level, listening_raw, reading_raw, listening_scaled, reading_scaled FROM toeic_test_results WHERE test_session = ?');
    $stmt->bind_param('s', $testSession);
    $stmt->execute();
    $out['results'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $out;
}

/* ---------------------------------------------------------------- CLI */
/** Isolated fixture operation: cli. */
function saverec_cli(array $argv): void {
    $args = [];
    foreach ($argv as $a) {
        if (str_starts_with($a, '--')) {
            $kv = explode('=', substr($a, 2), 2);
            $args[$kv[0]] = $kv[1] ?? true;
        }
    }
    $action = $args['action'] ?? 'ping';
    if ($action === 'ping') {
        $c = saverec_db([]);
        $meta = $c->query('SELECT @@port AS port, @@datadir AS datadir')->fetch_assoc();
        echo json_encode(['ok' => true, 'port' => (int)$meta['port'], 'datadir' => $meta['datadir']]) . PHP_EOL;
        return;
    }
    if ($action === 'hash-sources') {
        $root = $args['root'] ?? '';
        $files = ['user/test_toeic.php', 'user/ajax_save_toeic_answer.php', 'user/ajax_submit_section_toeic.php', 'user/result_toeic.php', 'includes/session_handler.php', 'includes/csrf_helper.php', 'includes/toeic_scorer.php', 'includes/toeic_media_guard.php'];
        $out = [];
        foreach ($files as $f) {
            $p = rtrim($root, '/\\') . '/' . $f;
            $out[$f] = is_file($p) ? hash_file('sha256', $p) : null;
        }
        echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
        return;
    }
    if ($action === 'init') {
        $c = saverec_db([]);
        $db = (string)($args['db'] ?? '');
        if (!preg_match('/^toeic_saverec_[0-9a-f]{8}$/D', $db)) { saverec_fail('dedicated random --db required'); }
        $c->query('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $c->select_db($db);
        saverec_schema($c);
        [$testSession, $lQids, $rQids] = saverec_seed($c);
        [$sid, $csrf] = saverec_make_session($c, $testSession);
        echo json_encode(['db' => $db, 'test_session' => $testSession, 'listening_qids' => $lQids, 'reading_qids' => $rQids, 'user_id' => SAVEREC_USER_ID, 'phpsessid' => $sid, 'csrf' => $csrf]) . PHP_EOL;
        return;
    }
    $db = (string)($args['db'] ?? '');
    if ($db === '') {
        saverec_fail('--db required');
    }
    $c = saverec_db(['db' => $db]);
    if ($action === 'drop-schema') {
        saverec_assert_isolated($c);
        $c->query('DROP DATABASE `' . $db . '`');
        $verify = $c->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $verify->bind_param('s', $db);
        $verify->execute();
        $absent = $verify->get_result()->fetch_assoc() === null;
        $verify->close();
        if (!$absent) { saverec_fail('Owned schema still exists after drop'); }
        echo json_encode(['ok' => true, 'schemaAbsent' => true]) . PHP_EOL;
        return;
    }
    if ($action === 'add-session') {
        $testSession = (string)($args['session'] ?? '');
        if ($testSession === '') {
            saverec_fail('--session required');
        }
        [$sid, $csrf] = saverec_make_session($c, $testSession);
        echo json_encode(['phpsessid' => $sid, 'csrf' => $csrf]) . PHP_EOL;
        return;
    }
    if ($action === 'seed-attempt') {
        // Fresh synthetic attempt reusing the seeded bank (per-test isolation).
        $lQids = [];
        $stmt = $c->prepare("SELECT id_soal, jawaban_benar FROM toeic_soal_listening WHERE part = '2' ORDER BY id_soal LIMIT 4");
        $stmt->execute();
        $lRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $rQids = [];
        $stmt = $c->prepare("SELECT id_soal, jawaban_benar FROM toeic_soal_reading WHERE part = '5' ORDER BY id_soal LIMIT 4");
        $stmt->execute();
        $rRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($lRows) < 4 || count($rRows) < 4) {
            saverec_fail('bank not seeded');
        }
        $testSession = 'toeic_e2e_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $uid = SAVEREC_USER_ID;
        $stmt = $c->prepare("INSERT INTO toeic_test_sessions (test_session, user_id, current_section, status, practice_mode) VALUES (?, ?, 'listening', 'active', 0)");
        $stmt->bind_param('si', $testSession, $uid);
        $stmt->execute();
        $stmt->close();
        $order = 1;
        foreach ($lRows as $r) {
            $lQids[] = (int)$r['id_soal'];
            saverec_assign($c, $testSession, (int)$r['id_soal'], 'listening', '2', $order++, (string)$r['jawaban_benar']);
        }
        $order = 1;
        foreach ($rRows as $r) {
            $rQids[] = (int)$r['id_soal'];
            saverec_assign($c, $testSession, (int)$r['id_soal'], 'reading', '5', $order++, (string)$r['jawaban_benar']);
        }
        [$sid, $csrf] = saverec_make_session($c, $testSession);
        echo json_encode(['test_session' => $testSession, 'listening_qids' => $lQids, 'reading_qids' => $rQids, 'phpsessid' => $sid, 'csrf' => $csrf]) . PHP_EOL;
        return;
    }
    if ($action === 'db-get') {
        $testSession = (string)($args['session'] ?? '');
        echo json_encode(saverec_db_dump($c, $testSession)) . PHP_EOL;
        return;
    }
    if ($action === 'set-timer') {
        // Move the section clock so that <remaining> seconds are left.
        $sid = (string)($args['sid'] ?? '');
        $testSession = (string)($args['session'] ?? '');
        $section = (string)($args['section'] ?? 'listening');
        $remaining = (int)($args['remaining'] ?? 60);
        if (!in_array($section, ['listening', 'reading'], true)) {
            saverec_fail('bad section');
        }
        $full = $section === 'listening' ? 2700 : 4500; // mirrors getToeicTimerSeconds() full mode
        $start = time() - ($full - $remaining);
        saverec_load_session($c, $sid);
        $_SESSION['section_start_time'] = $start;
        if (!is_array($_SESSION['toeic_section_start_times'] ?? null)) {
            $_SESSION['toeic_section_start_times'] = [];
        }
        $_SESSION['toeic_section_start_times'][$testSession . ':' . $section] = $start;
        $_SESSION['LAST_ACTIVITY'] = time();
        saverec_save_session($c, $sid);
        echo json_encode(['ok' => true, 'remaining' => $remaining]) . PHP_EOL;
        return;
    }
    saverec_fail('unknown action: ' . $action);
}

/* ------------------------------------------------- CLI entry (CLI SAPI only;
 * any other SAPI already exited above before DB/network use). */
saverec_cli($argv);
