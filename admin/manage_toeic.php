<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_asset_storage.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
$csrf_token = generateCsrfToken();
$tab = $_GET['tab'] ?? 'questions';
if (!in_array($tab, ['questions', 'audio', 'images'], true)) {
    $tab = 'questions';
}

function countRows(mysqli $conn, string $table): int {
    $res = $conn->query("SELECT COUNT(*) AS total FROM `$table`");
    return $res ? (int)$res->fetch_assoc()['total'] : 0;
}

$listening_questions = $conn->query("
    SELECT sl.*, ta.judul AS audio_title, ta.file_path AS audio_path, tp.file_path AS photo_path
    FROM toeic_soal_listening sl
    LEFT JOIN toeic_audio ta ON sl.id_audio = ta.id_audio
    LEFT JOIN toeic_photos tp ON ta.id_photo = tp.id_photo
    ORDER BY CAST(sl.part AS UNSIGNED), sl.nomor_soal, sl.id_soal
");
$reading_questions = $conn->query("
    SELECT sr.*, tt.judul AS text_title
    FROM toeic_soal_reading sr
    LEFT JOIN toeic_teks tt ON sr.id_teks = tt.id_teks
    ORDER BY CAST(sr.part AS UNSIGNED), sr.nomor_soal, sr.id_soal
");
$audio_rows = $conn->query("
    SELECT ta.*, tp.file_path AS photo_path, COUNT(sl.id_soal) AS usage_count
    FROM toeic_audio ta
    LEFT JOIN toeic_photos tp ON ta.id_photo = tp.id_photo
    LEFT JOIN toeic_soal_listening sl ON sl.id_audio = ta.id_audio
    GROUP BY ta.id_audio
    ORDER BY CAST(ta.part AS UNSIGNED), ta.id_audio
");
$image_rows = $conn->query("
    SELECT tp.*, COUNT(ta.id_audio) AS usage_count
    FROM toeic_photos tp
    LEFT JOIN toeic_audio ta ON ta.id_photo = tp.id_photo
    GROUP BY tp.id_photo
    ORDER BY tp.id_photo
");
$audio_catalog = $conn->query("SELECT id_audio, judul, part FROM toeic_audio ORDER BY CAST(part AS UNSIGNED), id_audio")->fetch_all(MYSQLI_ASSOC);
$text_catalog = $conn->query("SELECT id_teks, judul, part, text_type, isi_teks, isi_teks_2, isi_teks_3 FROM toeic_teks ORDER BY CAST(part AS UNSIGNED), id_teks")->fetch_all(MYSQLI_ASSOC);
$image_catalog = $conn->query("SELECT id_photo, description FROM toeic_photos ORDER BY id_photo")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOEIC Manager - <?php echo htmlspecialchars($website_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .card-lite { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 18px; padding: 1.25rem; margin-bottom: 1.5rem; }
        .asset-preview { width: 88px; height: 64px; object-fit: cover; border-radius: 10px; border: 1px solid rgba(255,255,255,0.12); }
        .tab-nav .nav-link { color: var(--text-secondary); border-radius: 999px; }
        .tab-nav .nav-link.active { background: rgba(37,99,235,0.14); color: #93c5fd; }
        .url-preview { max-width: 320px; overflow-wrap: anywhere; font-size: 0.82rem; }
        .question-tools-stack { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(320px, 0.9fr); gap: 1rem; align-items: start; }
        .question-tools-stack .card-lite { margin-bottom: 0; }
        .quick-question-panel { order: 1; }
        .quick-text-panel { order: 2; }
        .question-bank-panel { order: 3; grid-column: 1 / -1; }
        .form-error { display: none; border: 1px solid rgba(239,68,68,0.35); background: rgba(239,68,68,0.12); color: #fecaca; border-radius: 12px; padding: 0.75rem 1rem; }
        .form-error.is-visible { display: block; }
        @media (max-width: 992px) { .question-tools-stack { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                    <div>
                        <div class="small text-uppercase text-muted fw-semibold">TOEIC Asset Manager</div>
                        <h1 class="fw-bold mb-1">Questions, Audio, and Images</h1>
                        <p class="text-muted mb-0">Manager proper untuk bank soal TOEIC dengan URL publik audio dan image.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="toeic_question_bank_preview.php" target="_blank" class="btn btn-outline-light">Inspector</a>
                        <a href="../scripts/audit_toeic_question_bank.php" target="_blank" class="btn btn-outline-light">Audit</a>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3"><div class="card-lite"><div class="small text-muted mb-1">Questions</div><div class="h3 fw-bold mb-0"><?php echo countRows($conn, 'toeic_soal_listening') + countRows($conn, 'toeic_soal_reading'); ?></div></div></div>
                    <div class="col-md-3"><div class="card-lite"><div class="small text-muted mb-1">Audio Rows</div><div class="h3 fw-bold mb-0"><?php echo countRows($conn, 'toeic_audio'); ?></div></div></div>
                    <div class="col-md-3"><div class="card-lite"><div class="small text-muted mb-1">Image Rows</div><div class="h3 fw-bold mb-0"><?php echo countRows($conn, 'toeic_photos'); ?></div></div></div>
                    <div class="col-md-3"><div class="card-lite"><div class="small text-muted mb-1">Text Rows</div><div class="h3 fw-bold mb-0"><?php echo countRows($conn, 'toeic_teks'); ?></div></div></div>
                </div>

                <ul class="nav tab-nav mb-4">
                    <li class="nav-item"><a class="nav-link <?php echo $tab === 'questions' ? 'active' : ''; ?>" href="?tab=questions">Questions</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab === 'audio' ? 'active' : ''; ?>" href="?tab=audio">Audio</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab === 'images' ? 'active' : ''; ?>" href="?tab=images">Images</a></li>
                </ul>

                <?php if ($tab === 'questions'): ?>
                    <div class="question-tools-stack">
                    <div class="card-lite question-bank-panel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0">Question Bank</h5>
                            <div class="small text-muted">Use existing editors for detail edits. This page focuses on overview, linking, and preview.</div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Question</th>
                                        <th>Linked Asset</th>
                                        <th>Answer</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $listening_questions->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">Listening · Part <?php echo htmlspecialchars((string)$row['part']); ?> · #<?php echo (int)$row['nomor_soal']; ?></div>
                                                <div class="small"><?php echo htmlspecialchars($row['pertanyaan']); ?></div>
                                                <div class="small text-muted mt-1"><?php echo htmlspecialchars((string)$row['question_type']); ?></div>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['audio_title'])): ?>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['audio_title']); ?></div>
                                                    <audio controls preload="none" style="max-width:220px;"><source src="<?php echo htmlspecialchars(toeicAudioUrl((string)$row['audio_path'])); ?>"></audio>
                                                    <?php if (!empty($row['photo_path'])): ?><div class="mt-2"><img src="<?php echo htmlspecialchars(toeicPhotoUrl((string)$row['photo_path'])); ?>" class="asset-preview" alt="preview"></div><?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-danger">No audio linked</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-success"><?php echo htmlspecialchars((string)$row['jawaban_benar']); ?></span></td>
                                            <td class="text-end">
                                                <a href="edit_toeic_question.php?id=<?php echo (int)$row['id_soal']; ?>&type=listening" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-delete-question" data-id="<?php echo (int)$row['id_soal']; ?>" data-section="listening">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <?php while ($row = $reading_questions->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">Reading · Part <?php echo htmlspecialchars((string)$row['part']); ?> · #<?php echo (int)$row['nomor_soal']; ?></div>
                                                <div class="small"><?php echo htmlspecialchars($row['pertanyaan']); ?></div>
                                                <div class="small text-muted mt-1"><?php echo htmlspecialchars((string)$row['question_type']); ?></div>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['text_title'])): ?>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['text_title']); ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted"><?php echo in_array((string)$row['part'], ['6', '7'], true) ? 'No text linked' : 'Not required'; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-success"><?php echo htmlspecialchars((string)$row['jawaban_benar']); ?></span></td>
                                            <td class="text-end">
                                                <a href="edit_toeic_question.php?id=<?php echo (int)$row['id_soal']; ?>&type=reading" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if (!empty($row['id_teks'])): ?><button type="button" class="btn btn-sm btn-outline-info btn-open-text" data-text-id="<?php echo (int)$row['id_teks']; ?>">Text</button><?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-delete-question" data-id="<?php echo (int)$row['id_soal']; ?>" data-section="reading">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card-lite quick-question-panel">
                        <h5 class="fw-bold mb-3">Create Question</h5>
                        <form id="questionForm" method="post" action="ajax_toeic_manager.php">
                            <input type="hidden" name="action" value="question_save">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div id="question_error" class="form-error mb-3"></div>
                            <div class="row g-3">
                                <div class="col-md-3"><label class="form-label">Section</label><select name="section" id="question_section" class="form-select"><option value="listening">Listening</option><option value="reading">Reading</option></select></div>
                                <div class="col-md-3"><label class="form-label">Part</label><select name="part" id="question_part" class="form-select"></select></div>
                                <div class="col-md-3"><label class="form-label">Question No.</label><input type="number" name="nomor_soal" class="form-control" min="1" required></div>
                                <div class="col-md-3"><label class="form-label">Question Type</label><input type="text" name="question_type" class="form-control" placeholder="Blank = default by part"></div>
                                <div class="col-12"><label class="form-label">Question</label><textarea name="pertanyaan" class="form-control" rows="3" required></textarea></div>
                                <div class="col-md-6"><label class="form-label">Option A</label><input type="text" name="opsi_a" class="form-control" required></div>
                                <div class="col-md-6"><label class="form-label">Option B</label><input type="text" name="opsi_b" class="form-control" required></div>
                                <div class="col-md-6"><label class="form-label">Option C</label><input type="text" name="opsi_c" class="form-control" required></div>
                                <div class="col-md-6"><label class="form-label">Option D</label><input type="text" name="opsi_d" id="question_opsi_d" class="form-control"></div>
                                <div class="col-md-4"><label class="form-label">Correct</label><select name="jawaban_benar" id="question_correct" class="form-select"><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option></select></div>
                                <div class="col-md-8"><label class="form-label">Linked Audio</label><select name="id_audio" class="form-select"><option value="">Select audio (listening only)</option><?php foreach ($audio_catalog as $audio): ?><option value="<?php echo (int)$audio['id_audio']; ?>">#<?php echo (int)$audio['id_audio']; ?> · Part <?php echo htmlspecialchars((string)$audio['part']); ?> · <?php echo htmlspecialchars($audio['judul']); ?></option><?php endforeach; ?></select></div>
                                <div class="col-12"><label class="form-label">Linked Text</label><select name="id_teks" class="form-select"><option value="">Select text (Part 6/7 only)</option><?php foreach ($text_catalog as $text): ?><option value="<?php echo (int)$text['id_teks']; ?>">#<?php echo (int)$text['id_teks']; ?> · Part <?php echo htmlspecialchars((string)$text['part']); ?> · <?php echo htmlspecialchars($text['judul']); ?></option><?php endforeach; ?></select></div>
                                <div class="col-12"><label class="form-label">Explanation</label><textarea name="explanation" class="form-control" rows="4" required></textarea></div>
                                <div class="col-12"><button type="submit" class="btn btn-primary">Save Question</button></div>
                            </div>
                        </form>
                    </div>

                    <div class="card-lite quick-text-panel" id="quickEditors">
                        <h5 class="fw-bold mb-3">Quick Text Editor</h5>
                        <form id="textForm" method="post" action="ajax_toeic_manager.php">
                            <input type="hidden" name="action" value="text_save">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="id_teks" id="text_id_teks" value="">
                            <div class="row g-3">
                                <div class="col-md-3"><label class="form-label">Part</label><select name="part" id="text_part" class="form-select"><option value="6">Part 6</option><option value="7">Part 7</option></select></div>
                                <div class="col-md-9"><label class="form-label">Title</label><input type="text" name="judul" id="text_judul" class="form-control" required></div>
                                <div class="col-md-4"><label class="form-label">Text Type</label><input type="text" name="text_type" id="text_text_type" class="form-control"></div>
                                <div class="col-12"><label class="form-label">Primary Text</label><textarea name="isi_teks" id="text_isi_teks" class="form-control" rows="7" required></textarea></div>
                                <div class="col-md-6"><label class="form-label">Secondary Text</label><textarea name="isi_teks_2" id="text_isi_teks_2" class="form-control" rows="4"></textarea></div>
                                <div class="col-md-6"><label class="form-label">Tertiary Text</label><textarea name="isi_teks_3" id="text_isi_teks_3" class="form-control" rows="4"></textarea></div>
                                <div class="col-12"><button type="submit" class="btn btn-primary">Save Text</button></div>
                            </div>
                        </form>
                    </div>
                    </div>
                <?php elseif ($tab === 'audio'): ?>
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card-lite">
                                <h5 class="fw-bold mb-3">Audio Library</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead><tr><th>Audio</th><th>Preview</th><th>Image</th><th>Usage</th><th class="text-end">Actions</th></tr></thead>
                                        <tbody>
                                            <?php while ($row = $audio_rows->fetch_assoc()): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['judul']); ?></div>
                                                        <div class="small text-muted">Part <?php echo htmlspecialchars((string)$row['part']); ?></div>
                                                        <div class="url-preview mt-2"><?php echo htmlspecialchars((string)$row['file_path']); ?></div>
                                                    </td>
                                                    <td><audio controls preload="none" style="max-width:220px;"><source src="<?php echo htmlspecialchars(toeicAudioUrl((string)$row['file_path'])); ?>"></audio></td>
                                                    <td><?php if (!empty($row['photo_path'])): ?><img src="<?php echo htmlspecialchars(toeicPhotoUrl((string)$row['photo_path'])); ?>" class="asset-preview" alt="preview"><?php else: ?><span class="text-muted">No image</span><?php endif; ?></td>
                                                    <td><span class="badge bg-info"><?php echo (int)$row['usage_count']; ?></span></td>
                                                    <td class="text-end">
                                                        <button type="button" class="btn btn-sm btn-outline-primary btn-open-audio" data-audio='<?php echo htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'>Edit</button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-audio" data-id="<?php echo (int)$row['id_audio']; ?>" data-usage="<?php echo (int)$row['usage_count']; ?>">Delete</button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="card-lite">
                                <h5 class="fw-bold mb-3">Create or Update Audio</h5>
                                <form id="audioForm" method="post" action="ajax_toeic_manager.php">
                                    <input type="hidden" name="action" value="audio_save">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="id_audio" id="audio_id_audio" value="">
                                    <div class="row g-3">
                                        <div class="col-md-4"><label class="form-label">Part</label><select name="part" id="audio_part" class="form-select"><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?php echo $i; ?>">Part <?php echo $i; ?></option><?php endfor; ?></select></div>
                                        <div class="col-md-8"><label class="form-label">Title</label><input type="text" name="judul" id="audio_judul" class="form-control" required></div>
                                        <div class="col-12"><label class="form-label">Public Audio URL</label><input type="url" name="file_path" id="audio_file_path" class="form-control" placeholder="https://pub-...r2.dev/toeic_audio/file.mp3" required></div>
                                        <div class="col-md-8"><label class="form-label">Linked Image</label><select name="id_photo" id="audio_id_photo" class="form-select"><option value="">No image</option><?php foreach ($image_catalog as $image): ?><option value="<?php echo (int)$image['id_photo']; ?>">#<?php echo (int)$image['id_photo']; ?> · <?php echo htmlspecialchars($image['description']); ?></option><?php endforeach; ?></select></div>
                                        <div class="col-md-4"><label class="form-label">Context</label><input type="text" name="context" id="audio_context" class="form-control"></div>
                                        <div class="col-12"><label class="form-label">Transcript</label><textarea name="transcript" id="audio_transcript" class="form-control" rows="8" required></textarea></div>
                                        <div class="col-12"><button type="submit" class="btn btn-primary">Save Audio</button></div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card-lite">
                                <h5 class="fw-bold mb-3">Image Library</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead><tr><th>Preview</th><th>Description</th><th>Usage</th><th class="text-end">Actions</th></tr></thead>
                                        <tbody>
                                            <?php while ($row = $image_rows->fetch_assoc()): ?>
                                                <tr>
                                                    <td><img src="<?php echo htmlspecialchars(toeicPhotoUrl((string)$row['file_path'])); ?>" class="asset-preview" alt="preview"></td>
                                                    <td><div class="fw-semibold"><?php echo htmlspecialchars($row['description']); ?></div><div class="url-preview mt-2"><?php echo htmlspecialchars((string)$row['file_path']); ?></div></td>
                                                    <td><span class="badge bg-info"><?php echo (int)$row['usage_count']; ?></span></td>
                                                    <td class="text-end">
                                                        <button type="button" class="btn btn-sm btn-outline-primary btn-open-image" data-image='<?php echo htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'>Edit</button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-image" data-id="<?php echo (int)$row['id_photo']; ?>" data-usage="<?php echo (int)$row['usage_count']; ?>">Delete</button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="card-lite">
                                <h5 class="fw-bold mb-3">Create or Update Image</h5>
                                <form id="imageForm" method="post" action="ajax_toeic_manager.php">
                                    <input type="hidden" name="action" value="image_save">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="id_photo" id="image_id_photo" value="">
                                    <div class="row g-3">
                                        <div class="col-12"><label class="form-label">Public Image URL</label><input type="url" name="file_path" id="image_file_path" class="form-control" placeholder="https://pub-...r2.dev/toeic_photos/file.jpg" required></div>
                                        <div class="col-12"><label class="form-label">Description</label><textarea name="description" id="image_description" class="form-control" rows="5" required></textarea></div>
                                        <div class="col-12"><button type="submit" class="btn btn-primary">Save Image</button></div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        const textCatalog = <?php echo json_encode($text_catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const questionPartOptions = {
            listening: ['1', '2', '3', '4'],
            reading: ['5', '6', '7'],
        };

        function setFormError(form, message) {
            const inlineError = form.id === 'questionForm' ? document.getElementById('question_error') : null;
            if (inlineError) {
                inlineError.textContent = message;
                inlineError.classList.add('is-visible');
                return;
            }
            alert(message);
        }

        function clearFormError(form) {
            const inlineError = form.id === 'questionForm' ? document.getElementById('question_error') : null;
            if (!inlineError) return;
            inlineError.textContent = '';
            inlineError.classList.remove('is-visible');
        }

        function syncQuestionPartOptions() {
            const form = document.getElementById('questionForm');
            if (!form) return;
            const sectionSelect = document.getElementById('question_section');
            const partSelect = document.getElementById('question_part');
            const correctSelect = document.getElementById('question_correct');
            const optionD = document.getElementById('question_opsi_d');
            const allowedParts = questionPartOptions[sectionSelect.value] || questionPartOptions.listening;
            const currentPart = allowedParts.includes(partSelect.value) ? partSelect.value : allowedParts[0];

            partSelect.innerHTML = '';
            allowedParts.forEach((part) => {
                const option = document.createElement('option');
                option.value = part;
                option.textContent = `Part ${part}`;
                option.selected = part === currentPart;
                partSelect.appendChild(option);
            });

            const isPart2 = partSelect.value === '2';
            optionD.disabled = isPart2;
            optionD.required = !isPart2;
            if (isPart2) {
                optionD.value = '';
            }
            Array.from(correctSelect.options).forEach((option) => {
                option.disabled = isPart2 && option.value === 'D';
            });
            if (isPart2 && correctSelect.value === 'D') {
                correctSelect.value = 'A';
            }

            const audioSelect = form.elements.id_audio;
            const textSelect = form.elements.id_teks;
            if (audioSelect) {
                audioSelect.disabled = sectionSelect.value !== 'listening';
                audioSelect.required = sectionSelect.value === 'listening';
            }
            if (textSelect) {
                textSelect.disabled = sectionSelect.value !== 'reading' || !['6', '7'].includes(partSelect.value);
                textSelect.required = sectionSelect.value === 'reading' && ['6', '7'].includes(partSelect.value);
            }
        }

        function validateQuestionForm(form) {
            const section = form.elements.section?.value || '';
            const part = form.elements.part?.value || '';
            const allowedParts = questionPartOptions[section] || [];
            if (!allowedParts.includes(part)) {
                setFormError(form, 'Part tidak sesuai dengan section TOEIC yang dipilih.');
                return false;
            }
            if (part === '2' && form.elements.jawaban_benar?.value === 'D') {
                setFormError(form, 'Part 2 hanya memakai pilihan A, B, atau C.');
                return false;
            }
            return true;
        }

        async function submitManagerForm(form) {
            clearFormError(form);
            if (form.id === 'questionForm' && !validateQuestionForm(form)) {
                return;
            }

            const submitButton = form.querySelector('button[type="submit"]');
            const originalLabel = submitButton ? submitButton.textContent : '';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Saving...';
            }

            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
                const result = await response.json();
                if (!result.success) {
                    setFormError(form, result.error || 'Request failed');
                    return;
                }
                window.location.reload();
            } catch (error) {
                setFormError(form, error.message || 'Request failed');
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = originalLabel;
                }
            }
        }

        ['questionForm', 'textForm', 'audioForm', 'imageForm'].forEach((formId) => {
            const form = document.getElementById(formId);
            if (!form) return;
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submitManagerForm(form);
            });
        });

        document.getElementById('question_section')?.addEventListener('change', syncQuestionPartOptions);
        document.getElementById('question_part')?.addEventListener('change', syncQuestionPartOptions);
        syncQuestionPartOptions();

        async function managerDelete(payload) {
            const formData = new FormData();
            formData.append('csrf_token', <?php echo json_encode($csrf_token, JSON_UNESCAPED_SLASHES); ?>);
            Object.entries(payload).forEach(([key, value]) => formData.append(key, value));
            const response = await fetch('ajax_toeic_manager.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) {
                alert(result.error || 'Delete failed');
                return;
            }
            window.location.reload();
        }

        document.querySelectorAll('.btn-delete-question').forEach((button) => {
            button.addEventListener('click', () => {
                if (!confirm('Delete this question?')) return;
                managerDelete({ action: 'question_delete', id_soal: button.dataset.id, section: button.dataset.section });
            });
        });
        document.querySelectorAll('.btn-delete-audio').forEach((button) => {
            button.addEventListener('click', () => {
                const usage = parseInt(button.dataset.usage || '0', 10);
                let force = '0';
                if (usage > 0) {
                    if (!confirm(`Audio is linked to ${usage} question(s). Force unlink and delete?`)) return;
                    force = '1';
                } else if (!confirm('Delete this audio?')) {
                    return;
                }
                managerDelete({ action: 'audio_delete', id_audio: button.dataset.id, force_unlink: force });
            });
        });
        document.querySelectorAll('.btn-delete-image').forEach((button) => {
            button.addEventListener('click', () => {
                const usage = parseInt(button.dataset.usage || '0', 10);
                let force = '0';
                if (usage > 0) {
                    if (!confirm(`Image is linked to ${usage} audio row(s). Force unlink and delete?`)) return;
                    force = '1';
                } else if (!confirm('Delete this image?')) {
                    return;
                }
                managerDelete({ action: 'image_delete', id_photo: button.dataset.id, force_unlink: force });
            });
        });

        document.querySelectorAll('.btn-open-text').forEach((button) => {
            button.addEventListener('click', () => {
                const id = parseInt(button.dataset.textId, 10);
                const row = textCatalog.find((item) => parseInt(item.id_teks, 10) === id);
                if (!row) return;
                document.getElementById('text_id_teks').value = row.id_teks;
                document.getElementById('text_part').value = row.part;
                document.getElementById('text_judul').value = row.judul || '';
                document.getElementById('text_text_type').value = row.text_type || '';
                document.getElementById('text_isi_teks').value = row.isi_teks || '';
                document.getElementById('text_isi_teks_2').value = row.isi_teks_2 || '';
                document.getElementById('text_isi_teks_3').value = row.isi_teks_3 || '';
                const quickEditors = document.getElementById('quickEditors');
                window.scrollTo({ top: (quickEditors || document.getElementById('textForm')).offsetTop - 120, behavior: 'smooth' });
                document.getElementById('text_isi_teks').focus();
            });
        });
        document.querySelectorAll('.btn-open-audio').forEach((button) => {
            button.addEventListener('click', () => {
                const row = JSON.parse(button.dataset.audio);
                document.getElementById('audio_id_audio').value = row.id_audio || '';
                document.getElementById('audio_part').value = row.part || '1';
                document.getElementById('audio_judul').value = row.judul || '';
                document.getElementById('audio_file_path').value = row.file_path || '';
                document.getElementById('audio_id_photo').value = row.id_photo || '';
                document.getElementById('audio_context').value = row.context || '';
                document.getElementById('audio_transcript').value = row.transcript || '';
                window.scrollTo({ top: document.getElementById('audioForm').offsetTop - 120, behavior: 'smooth' });
            });
        });
        document.querySelectorAll('.btn-open-image').forEach((button) => {
            button.addEventListener('click', () => {
                const row = JSON.parse(button.dataset.image);
                document.getElementById('image_id_photo').value = row.id_photo || '';
                document.getElementById('image_file_path').value = row.file_path || '';
                document.getElementById('image_description').value = row.description || '';
                window.scrollTo({ top: document.getElementById('imageForm').offsetTop - 120, behavior: 'smooth' });
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
