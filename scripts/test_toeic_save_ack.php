<?php
declare(strict_types=1);
/**
 * TOEIC save acknowledgement guard — portable isolated verification.
 *
 * ROOT is derived from this file's location (candidate worktree), never hardcoded.
 * All locations/ports are explicit + configurable via env; the expected loopback
 * port and owned datadir are asserted BEFORE any database write. Refuses the
 * ordinary production port 3306 and any foreign datadir.
 *
 * Env:
 *   TOEIC_ACK_PORT      expected MariaDB port            (default 13362)
 *   TOEIC_ACK_DATADIR   exact absolute owned datadir      (default ARTDir/mariadb-data)
 *   TOEIC_ACK_HTTP_PORT PHP builtin-server port          (default 16861)
 *   TOEIC_ACK_ARTDIR    JSON/docroot artifact dir        (default system temp/toeic-save-ack)
 *
 * Usage: php scripts/test_toeic_save_ack.php --label=baseline|candidate
 * Exit: 0 all pass · 1 assertion failure · 2 identity-guard refusal / harness error.
 */

// This destructive synthetic-fixture harness must never run via the web server.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$ROOT = dirname(__DIR__);
$label = 'candidate';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--label=')) { $label = substr($arg, 8); }
}
if (!preg_match('/^[a-z0-9_-]+$/i', $label)) { fwrite(STDERR, "bad --label\n"); exit(2); }

$ARTDIR = getenv('TOEIC_ACK_ARTDIR') ?: sys_get_temp_dir() . '/toeic-save-ack';
$EXPECTED_PORT = (int)(getenv('TOEIC_ACK_PORT') ?: 13362);
$EXPECTED_DATADIR_SUFFIX = getenv('TOEIC_ACK_DATADIR') ?: $ARTDIR . '/mariadb-data';
$HTTP_PORT = (int)(getenv('TOEIC_ACK_HTTP_PORT') ?: 16861);
if ($EXPECTED_PORT === 3306) { fwrite(STDERR, "REFUSING production port 3306\n"); exit(2); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** Normalize directory separators and strip only trailing separators. */
function normdir(string $p): string {
    return rtrim(str_replace('\\', '/', $p), '/');
}

$server = null; $db = null; $docroot = null; $cases = [];
try {
    // ---- identity guard: verify exact loopback port + owned datadir before writes ----
    $server = new mysqli('127.0.0.1', 'root', '', '', $EXPECTED_PORT);
    $server->set_charset('utf8mb4');
    $id = $server->query('SELECT @@datadir AS datadir, @@port AS port')->fetch_assoc();
    $actualDatadir = normdir((string)$id['datadir']);
    $actualPort = (int)$id['port'];
    if ($actualPort !== $EXPECTED_PORT
        || $actualPort === 3306
        || $actualDatadir !== normdir($EXPECTED_DATADIR_SUFFIX)) {
        throw new RuntimeException(
            'REFUSING non-isolated DB: port=' . $actualPort . ' datadir=' . $id['datadir'], 2);
    }

    // ---- isolated database + minimal synthetic schema (created only, dropped in finally) ----
    $db = 'toeic_ack_' . bin2hex(random_bytes(4));
    $server->query('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $server->select_db($db);
    foreach ([
        "CREATE TABLE sessions (id VARCHAR(128) NOT NULL PRIMARY KEY, access INT UNSIGNED, data TEXT) ENGINE=InnoDB",
        "CREATE TABLE toeic_test_sessions (id INT AUTO_INCREMENT PRIMARY KEY, test_session VARCHAR(100) UNIQUE, user_id INT, current_section VARCHAR(20), status VARCHAR(20) DEFAULT 'active', practice_mode TINYINT DEFAULT 0, target_part VARCHAR(2)) ENGINE=InnoDB",
        "CREATE TABLE toeic_test_questions (id INT AUTO_INCREMENT PRIMARY KEY, test_session VARCHAR(100), user_id INT, question_id INT, section VARCHAR(20), part VARCHAR(2), user_answer VARCHAR(10) NULL, UNIQUE KEY uq(test_session,section,question_id)) ENGINE=InnoDB",
        "CREATE TABLE toeic_soal_listening (id_soal INT PRIMARY KEY, id_audio INT NULL) ENGINE=InnoDB",
        "CREATE TABLE toeic_audio (id_audio INT AUTO_INCREMENT PRIMARY KEY, id_photo INT NULL) ENGINE=InnoDB",
        "CREATE TABLE toeic_photos (id_photo INT AUTO_INCREMENT PRIMARY KEY, file_path TEXT) ENGINE=InnoDB",
    ] as $ddl) { $server->query($ddl); }

    $ins = function (string $sql, string $types, array $params) use ($server): void {
        $st = $server->prepare($sql); $st->bind_param($types, ...$params); $st->execute(); $st->close();
    };
    // Reading session (user 1) + foreign session (user 2) + listening session (user 1).
    $ins("INSERT INTO toeic_test_sessions (test_session,user_id,current_section,status) VALUES (?,?,?,?)",
        'siss', ['TS_READ', 1, 'reading', 'active']);
    $ins("INSERT INTO toeic_test_sessions (test_session,user_id,current_section,status) VALUES (?,?,?,?)",
        'siss', ['TS_FOREIGN', 2, 'reading', 'active']);
    $ins("INSERT INTO toeic_test_sessions (test_session,user_id,current_section,status) VALUES (?,?,?,?)",
        'siss', ['TS_LISTEN', 1, 'listening', 'active']);
    $ins("INSERT INTO toeic_test_questions (test_session,user_id,question_id,section,part,user_answer) VALUES (?,?,?,?,?,?)",
        'siisss', ['TS_READ', 1, 101, 'reading', '5', null]);
    $ins("INSERT INTO toeic_test_questions (test_session,user_id,question_id,section,part,user_answer) VALUES (?,?,?,?,?,?)",
        'siisss', ['TS_FOREIGN', 2, 101, 'reading', '5', null]);
    // Listening part-1 question whose photo path is empty => photo_unavailable.
    $ins("INSERT INTO toeic_test_questions (test_session,user_id,question_id,section,part,user_answer) VALUES (?,?,?,?,?,?)",
        'siisss', ['TS_LISTEN', 1, 201, 'listening', '1', null]);
    $ins("INSERT INTO toeic_soal_listening (id_soal,id_audio) VALUES (?,?)", 'ii', [201, 1]);
    $ins("INSERT INTO toeic_audio (id_photo) VALUES (?)", 'i', [1]);
    $ins("INSERT INTO toeic_photos (file_path) VALUES (?)", 's', ['']);

    // DB-backed PHP sessions for user 1 and user 2.
    $tok1 = bin2hex(random_bytes(32)); $tok2 = bin2hex(random_bytes(32));
    $sid1 = 'ACK' . bin2hex(random_bytes(8)); $sid2 = 'ACK' . bin2hex(random_bytes(8));
    $now = time();
    $ins("INSERT INTO sessions (id,access,data) VALUES (?,?,?)", 'sis',
        [$sid1, $now, 'user_id|i:1;csrf_token|s:64:"' . $tok1 . '";']);
    $ins("INSERT INTO sessions (id,access,data) VALUES (?,?,?)", 'sis',
        [$sid2, $now, 'user_id|i:2;csrf_token|s:64:"' . $tok2 . '";']);

    // ---- docroot built from THIS candidate tree + synthetic fixture config only ----
    if (!is_dir($ARTDIR) && !mkdir($ARTDIR, 0777, true)) { throw new RuntimeException('cannot mkdir artdir', 2); }
    $docroot = $ARTDIR . '/run-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '-docroot';
    mkdir($docroot . '/includes', 0777, true); mkdir($docroot . '/user', 0777, true);
    foreach (['session_handler.php', 'csrf_helper.php', 'toeic_helper.php', 'toeic_scorer.php',
              'toeic_media_guard.php', 'toeic_asset_storage.php'] as $f) {
        $src = $ROOT . '/includes/' . $f;
        if (!is_file($src)) { throw new RuntimeException('missing candidate dependency: ' . $f, 2); }
        copy($src, $docroot . '/includes/' . $f);
    }
    $endpointSrc = $ROOT . '/user/ajax_save_toeic_answer.php';
    if (!is_file($endpointSrc)) { throw new RuntimeException('missing candidate endpoint', 2); }
    copy($endpointSrc, $docroot . '/user/ajax_save_toeic_answer.php');
    file_put_contents($docroot . '/includes/config.php',
        "<?php\ndefine('FEATURE_TOEIC', true);\n"
        . "\$conn = new mysqli('127.0.0.1', 'root', '', '" . $db . "', " . $EXPECTED_PORT . ");\n"
        . "\$conn->set_charset('utf8mb4');\n");

    // ---- HTTP server on explicit loopback port ----
    $php = PHP_BINARY;
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptors = [0 => ['file', $nullDevice, 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']];
    $proc = proc_open([$php, '-S', '127.0.0.1:' . $HTTP_PORT, '-t', $docroot], $descriptors, $pipes);
    if (!is_resource($proc)) { throw new RuntimeException('cannot start php server', 2); }
    $base = 'http://127.0.0.1:' . $HTTP_PORT;
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        usleep(200000);
        $ch = curl_init($base . '/user/ajax_save_toeic_answer.php');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}',
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($code === 200 && $out !== false && json_decode($out, true) !== null) { $ready = true; break; }
    }
    if (!$ready) { proc_terminate($proc); proc_close($proc); throw new RuntimeException('php server not ready', 2); }

    $post = function (array $body, ?string $sid, ?string $tok) use ($base): array {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($tok !== null) { $headers[] = 'X-CSRF-Token: ' . $tok; }
        $ch = curl_init($base . '/user/ajax_save_toeic_answer.php');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        if ($sid !== null) { curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sid); }
        $raw = (string)curl_exec($ch); curl_close($ch);
        return ['raw' => $raw, 'json' => json_decode($raw, true)];
    };
    $dbAnswer = function (string $ts, string $section, int $qid) use ($server): ?string {
        $st = $server->prepare(
            'SELECT user_answer FROM toeic_test_questions WHERE test_session=? AND section=? AND question_id=?');
        $st->bind_param('ssi', $ts, $section, $qid); $st->execute();
        $row = $st->get_result()->fetch_assoc(); $st->close();
        return $row ? $row['user_answer'] : null;
    };
    $check = function (string $name, bool $pass, string $detail) use (&$cases): void {
        $cases[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
    };

    // T1 valid first save (reading).
    $r = $post(['test_session' => 'TS_READ', 'section' => 'reading', 'question_id' => 101, 'answer' => 'B'], $sid1, $tok1);
    $check('T1 valid first save', ($r['json']['success'] ?? null) === true && $dbAnswer('TS_READ', 'reading', 101) === 'B',
        "resp={$r['raw']} db='" . (string)$dbAnswer('TS_READ', 'reading', 101) . "'");
    // T2 repeat same answer must still succeed, row persists expected answer.
    $r = $post(['test_session' => 'TS_READ', 'section' => 'reading', 'question_id' => 101, 'answer' => 'B'], $sid1, $tok1);
    $check('T2 repeat same answer ack', ($r['json']['success'] ?? null) === true && $dbAnswer('TS_READ', 'reading', 101) === 'B',
        "resp={$r['raw']} db='" . (string)$dbAnswer('TS_READ', 'reading', 101) . "'");
    // T3 changed answer.
    $r = $post(['test_session' => 'TS_READ', 'section' => 'reading', 'question_id' => 101, 'answer' => 'C'], $sid1, $tok1);
    $check('T3 changed answer', ($r['json']['success'] ?? null) === true && $dbAnswer('TS_READ', 'reading', 101) === 'C',
        "resp={$r['raw']} db='" . (string)$dbAnswer('TS_READ', 'reading', 101) . "'");
    // T4 missing question rejects.
    $r = $post(['test_session' => 'TS_READ', 'section' => 'reading', 'question_id' => 9999, 'answer' => 'A'], $sid1, $tok1);
    $check('T4 missing question rejects', ($r['json']['success'] ?? null) === false
        && str_contains((string)($r['json']['error'] ?? ''), 'Question not found'), "resp={$r['raw']}");
    // T5 no-auth rejects.
    $r = $post(['test_session' => 'TS_READ', 'section' => 'reading', 'question_id' => 101, 'answer' => 'A'], null, $tok1);
    $check('T5 no-auth rejects', ($r['json']['success'] ?? null) === false
        && ($r['json']['error'] ?? '') === 'Unauthorized', "resp={$r['raw']}");
    // T6 bad CSRF rejects.
    $r = $post(['test_session' => 'TS_READ', 'section' => 'reading', 'question_id' => 101, 'answer' => 'A'], $sid1, 'bad-token');
    $check('T6 bad CSRF rejects', ($r['json']['success'] ?? null) === false
        && ($r['json']['error'] ?? '') === 'Invalid CSRF token', "resp={$r['raw']}");
    // T7 wrong section rejects.
    $r = $post(['test_session' => 'TS_LISTEN', 'section' => 'reading', 'question_id' => 101, 'answer' => 'A'], $sid1, $tok1);
    $check('T7 wrong section rejects', ($r['json']['success'] ?? null) === false
        && ($r['json']['error'] ?? '') === 'Section is no longer active', "resp={$r['raw']}");
    // T8 missing photo rejects (listening part 1, empty photo path).
    $r = $post(['test_session' => 'TS_LISTEN', 'section' => 'listening', 'question_id' => 201, 'answer' => 'A'], $sid1, $tok1);
    $check('T8 missing photo rejects', ($r['json']['success'] ?? null) === false
        && ($r['json']['error_code'] ?? '') === 'photo_unavailable'
        && $dbAnswer('TS_LISTEN', 'listening', 201) === null, "resp={$r['raw']}");
    // T9 foreign ownership rejects.
    $r = $post(['test_session' => 'TS_FOREIGN', 'section' => 'reading', 'question_id' => 101, 'answer' => 'A'], $sid1, $tok1);
    $check('T9 foreign ownership rejects', ($r['json']['success'] ?? null) === false
        && ($r['json']['error'] ?? '') === 'Session not found', "resp={$r['raw']}");

    proc_terminate($proc); proc_close($proc); $proc = null;

    $passed = count(array_filter($cases, fn($c) => $c['pass']));
    $failed = count($cases) - $passed;
    $srcBytes = file_get_contents($endpointSrc);
    $report = [
        'at' => date('c'), 'label' => $label, 'root' => $ROOT,
        'source' => $endpointSrc, 'source_sha256' => hash('sha256', (string)$srcBytes),
        'source_has_affected_rows_branch' => str_contains((string)$srcBytes, 'affected_rows'),
        'source_has_persisted_verify' => str_contains((string)$srcBytes, 'SELECT user_answer'),
        'db' => $db, 'datadir' => $id['datadir'], 'db_port' => $actualPort, 'http_port' => $HTTP_PORT,
        'docroot' => $docroot, 'passed' => $passed, 'failed' => $failed, 'cases' => $cases,
    ];
    if (!is_dir($ARTDIR)) { mkdir($ARTDIR, 0777, true); }
    $artifact = $ARTDIR . '/toeic-save-ack-' . $label . '.json';
    file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    // ---- cleanup first, verdict after ----
    $cleanupErrors = [];
    try { $server->query('DROP DATABASE IF EXISTS `' . $db . '`'); } catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
    $rmtree = function (string $dir) use (&$rmtree): void {
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $dir . '/' . $e; is_dir($p) ? $rmtree($p) : unlink($p);
        }
        rmdir($dir);
    };
    try { if ($docroot && is_dir($docroot)) { $rmtree($docroot); } } catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
    if ($cleanupErrors) { fwrite(STDERR, 'cleanup warnings: ' . implode(' | ', $cleanupErrors) . "\n"); }

    echo "label={$label} passed={$passed} failed={$failed} artifact={$artifact}\n";
    foreach ($cases as $c) { echo ($c['pass'] ? 'PASS' : 'FAIL') . ' ' . $c['name'] . ' :: ' . $c['detail'] . "\n"; }
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    if (isset($proc) && is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    if ($server instanceof mysqli && $db) { try { $server->query('DROP DATABASE IF EXISTS `' . $db . '`'); } catch (Throwable) {} }
    fwrite(STDERR, 'HARNESS ERROR: ' . $e->getMessage() . "\n");
    exit($e->getCode() === 2 ? 2 : 2);
}
