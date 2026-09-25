<?php
declare(strict_types=1);
/** Fresh synthetic schema helpers. Refuses every server except the isolated audit datadir. */
function toeicFixtureConnection(): array {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli('127.0.0.1', 'root', '', '', 23306);
    $server = $connection->query('SELECT @@datadir AS datadir, @@port AS port')->fetch_assoc();
    $expected = '/.workflow/toeic-bank-fix-20260923-1518/infra/data/';
    if ((int)$server['port'] !== 23306 || !str_ends_with(str_replace('\\', '/', $server['datadir']), $expected)) {
        throw new RuntimeException('REFUSING non-isolated database server');
    }
    $database = 'toeic_fix_' . bin2hex(random_bytes(6));
    $connection->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $connection->select_db($database);
    $connection->set_charset('utf8mb4');
    return [$connection, $database];
}

/** Create only new fixture tables, never drop or migrate an existing schema. */
function toeicFixtureSchema(mysqli $connection): void {
    $statements = [
        "CREATE TABLE toeic_photos (id_photo INT AUTO_INCREMENT PRIMARY KEY, file_path TEXT, description TEXT) ENGINE=InnoDB",
        "CREATE TABLE toeic_audio (id_audio INT AUTO_INCREMENT PRIMARY KEY, judul VARCHAR(255), part VARCHAR(2), file_path TEXT, transcript LONGTEXT, context TEXT, id_photo INT NULL) ENGINE=InnoDB",
        "CREATE TABLE toeic_teks (id_teks INT AUTO_INCREMENT PRIMARY KEY, judul VARCHAR(255), part VARCHAR(2), text_type VARCHAR(50), isi_teks LONGTEXT, isi_teks_2 LONGTEXT, isi_teks_3 LONGTEXT) ENGINE=InnoDB",
        "CREATE TABLE toeic_test_sessions (id INT AUTO_INCREMENT PRIMARY KEY, test_session VARCHAR(100) UNIQUE, user_id INT, current_section VARCHAR(20), status VARCHAR(20) DEFAULT 'active', practice_mode TINYINT DEFAULT 0, target_part VARCHAR(2), checkout_source VARCHAR(40), checkout_reference VARCHAR(120), started_at DATETIME DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL, fallback_count INT DEFAULT 0, fallback_breakdown LONGTEXT, short_filled_count INT DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE toeic_test_questions (id INT AUTO_INCREMENT PRIMARY KEY, test_session VARCHAR(100), user_id INT, question_id INT, question_type VARCHAR(80), section VARCHAR(20), part VARCHAR(2), question_order INT, stimulus_group_id VARCHAR(100), group_order INT, user_answer VARCHAR(10) NULL, is_correct TINYINT NULL, answered_at DATETIME NULL, snap_jawaban_benar VARCHAR(20), snap_pertanyaan LONGTEXT, snap_opsi_a TEXT, snap_opsi_b TEXT, snap_opsi_c TEXT, snap_opsi_d TEXT, original_question_id INT, UNIQUE KEY uniq_session_question(test_session,section,question_id)) ENGINE=InnoDB",
        "CREATE TABLE toeic_pool_exhausted_events (id INT AUTO_INCREMENT PRIMARY KEY, test_session VARCHAR(100), user_id INT, section VARCHAR(20), part VARCHAR(2), target_count INT, drawn_count INT, fallback_tier VARCHAR(30), seen_window_size INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
        "CREATE TABLE user_purchases (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, exam_type VARCHAR(20), transaction_ref VARCHAR(100), status ENUM('active','expired','revoked','used') DEFAULT 'active', purchase_date DATETIME DEFAULT CURRENT_TIMESTAMP, used_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE site_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value LONGTEXT) ENGINE=InnoDB",
    ];
    foreach (['toeic_soal_listening', 'toeic_soal_reading'] as $table) {
        $statements[] = "CREATE TABLE {$table} (id_soal INT AUTO_INCREMENT PRIMARY KEY, part VARCHAR(2), nomor_soal INT, pertanyaan LONGTEXT, opsi_a TEXT, opsi_b TEXT, opsi_c TEXT, opsi_d TEXT, jawaban_benar VARCHAR(50), explanation LONGTEXT, question_type VARCHAR(80), id_audio INT NULL, id_teks INT NULL) ENGINE=InnoDB";
    }
    foreach ($statements as $statement) { $connection->query($statement); }
}

/** Insert explicit synthetic fields using parameter binding. */
function toeicFixtureInsert(mysqli $connection, string $table, array $row): int {
    if (!preg_match('/^[a-z_]+$/', $table)) { throw new InvalidArgumentException('Unsafe fixture table'); }
    foreach (array_keys($row) as $column) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $column)) { throw new InvalidArgumentException('Unsafe fixture column'); }
    }
    $columns = implode(',', array_keys($row));
    $placeholders = implode(',', array_fill(0, count($row), '?'));
    $statement = $connection->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})");
    $values = array_values($row);
    $statement->bind_param(str_repeat('s', count($values)), ...$values);
    $statement->execute();
    $id = (int)$connection->insert_id;
    $statement->close();
    return $id;
}

/** Synthetic question values with independent IDs and clear content labels. */
function toeicFixtureQuestion(int $part, int $number, string $stem, ?int $audio, ?int $text): array {
    return ['part' => (string)$part, 'nomor_soal' => $number, 'pertanyaan' => $stem,
        'opsi_a' => 'Correct response', 'opsi_b' => 'Wrong response one', 'opsi_c' => 'Wrong response two',
        'opsi_d' => $part === 2 ? '' : 'Wrong response three', 'jawaban_benar' => 'A',
        'explanation' => 'Synthetic verification fixture, not educational bank content.',
        'question_type' => 'fixture_part_' . $part, 'id_audio' => $audio, 'id_teks' => $text];
}

/** Seed enough independent stimuli for two complete full tests, plus cloned reading records. */
function toeicFixtureSeed(mysqli $connection): void {
    for ($i = 1; $i <= 12; $i++) {
        $photo = toeicFixtureInsert($connection, 'toeic_photos', ['file_path' => sprintf('toeic_p1_%02d.jpg', (($i - 1) % 6) + 1), 'description' => 'SYNTH photo ' . $i]);
        $audio = toeicFixtureInsert($connection, 'toeic_audio', ['judul' => 'SYNTH P1 ' . $i, 'part' => '1', 'file_path' => 'toeic_p1_01.mp3', 'transcript' => 'SYNTH statements ' . $i, 'context' => 'fixture', 'id_photo' => $photo]);
        $row = toeicFixtureQuestion(1, $i, 'Choose a statement', $audio, null);
        $row['opsi_a'] = 'SYNTH visible action ' . $i;
        toeicFixtureInsert($connection, 'toeic_soal_listening', $row);
    }
    for ($i = 1; $i <= 50; $i++) {
        $audio = toeicFixtureInsert($connection, 'toeic_audio', ['judul' => 'SYNTH P2 ' . $i, 'part' => '2', 'file_path' => 'toeic_p2_01.mp3', 'transcript' => "SYNTH spoken prompt {$i}? Correct response Wrong response one Wrong response two", 'context' => 'fixture', 'id_photo' => null]);
        toeicFixtureInsert($connection, 'toeic_soal_listening', toeicFixtureQuestion(2, 100 + $i, 'Listen and choose a response.', $audio, null));
    }
    foreach ([3 => 26, 4 => 20] as $part => $groups) {
        for ($i = 1; $i <= $groups; $i++) {
            $audio = toeicFixtureInsert($connection, 'toeic_audio', ['judul' => "SYNTH P{$part} group {$i}", 'part' => (string)$part, 'file_path' => 'toeic_p3_01.mp3', 'transcript' => "SYNTH complete conversation {$part}:{$i}", 'context' => 'fixture', 'id_photo' => null]);
            for ($q = 1; $q <= 3; $q++) {
                toeicFixtureInsert($connection, 'toeic_soal_listening', toeicFixtureQuestion($part, $i * 3 + $q, 'SYNTH group question ' . $q, $audio, null));
            }
        }
    }
    for ($i = 1; $i <= 60; $i++) {
        $row = toeicFixtureQuestion(5, $i, 'SYNTH independent sentence ' . $i, null, null);
        toeicFixtureInsert($connection, 'toeic_soal_reading', $row);
        if ($i <= 30) {
            $row['nomor_soal'] += 1000;
            toeicFixtureInsert($connection, 'toeic_soal_reading', $row);
        }
    }
    foreach ([6 => 8, 7 => 48] as $part => $groups) {
        for ($i = 1; $i <= $groups; $i++) {
            $copies = $i <= 4 ? 2 : 1;
            for ($copy = 0; $copy < $copies; $copy++) {
                $text = toeicFixtureInsert($connection, 'toeic_teks', ['judul' => "SYNTH title {$part}:{$i} copy {$copy}", 'part' => (string)$part,
                    'text_type' => $part === 7 && $i % 3 === 0 ? 'triple' : 'single', 'isi_teks' => "SYNTH article {$part}:{$i} with ___1___ and ___2___ blanks",
                    'isi_teks_2' => $part === 7 && $i % 3 === 0 ? "SYNTH secondary article {$i}" : '',
                    'isi_teks_3' => $part === 7 && $i % 3 === 0 ? "SYNTH third article {$i}" : '']);
                $size = $part === 6 ? 4 : ($i % 2 === 0 ? 3 : 2);
                for ($q = 1; $q <= $size; $q++) {
                    toeicFixtureInsert($connection, 'toeic_soal_reading', toeicFixtureQuestion($part, $i * 10 + $q, 'SYNTH article question ' . $q, null, $text));
                }
            }
        }
    }
}
