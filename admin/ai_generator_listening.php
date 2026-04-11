<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();

// Fetch all listening audio into an array to be used safely multiple times
$audio_result = $conn->query("SELECT id_audio, judul FROM audio_listening ORDER BY id_audio DESC");
$all_audio = [];
if ($audio_result) {
    $all_audio = $audio_result->fetch_all(MYSQLI_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - AI Generator Listening</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
    <style>
        .btn-generate {
            background: var(--primary);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .btn-generate:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px var(--primary-glow);
            color: white;
        }

        .btn-save {
            background: var(--success);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .btn-save:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16,185,129,0.3);
            color: white;
        }

        .btn-status {
            background: var(--info);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .btn-status:hover {
            background: #0891b2;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(6,182,212,0.3);
            color: white;
        }

        .question-item {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .question-item:hover {
            background: var(--surface-hover);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .question-item h6 {
            color: var(--primary-hover);
            font-weight: bold;
            font-size: 1.2rem;
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="admin-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h1><i class="fas fa-headphones-alt me-3"></i>AI Generator Listening</h1>
                        <div class="text-end">
                            <button class="btn btn-status me-2" id="check-api-status">
                                <i class="fas fa-heartbeat me-2"></i>Check API Status
                            </button>
                            <a href="manage_listening.php" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Back to Listening
                            </a>
                        </div>
                    </div>
                </div>

                <div id="api-status-alert" style="display: none;" class="alert alert-dismissible fade show">
                    <div id="api-status-content"></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>

                <ul class="nav nav-tabs mb-4" id="aiGenTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="generate-tab" data-bs-toggle="tab"
                            data-bs-target="#generate" type="button" role="tab">1. Generate Transcript</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="questions-tab" data-bs-toggle="tab" data-bs-target="#questions"
                            type="button" role="tab">2. Generate Questions</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Step 1: Generate Transcript -->
                    <div class="tab-pane fade show active" id="generate" role="tabpanel">
                        <div class="content-card">
                            <h4>Generate Listening Transcript</h4>
                            <form id="form-generate-transcript">
                                <div class="mb-3">
                                    <label class="form-label">Prompt</label>
                                    <textarea class="form-control" name="prompt" rows="3"
                                        placeholder="e.g., A conversation between two students about a project deadline."></textarea>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Listening Type</label>
                                        <select class="form-select" name="listening_type">
                                            <option value="conversation">Conversation</option>
                                            <option value="lecture">Lecture</option>
                                            <option value="announcement">Announcement</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Difficulty</label>
                                        <select class="form-select" name="difficulty">
                                            <option value="easy">Easy</option>
                                            <option value="medium" selected>Medium</option>
                                            <option value="hard">Hard</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Approx. Duration (seconds)</label>
                                        <input type="number" class="form-control" name="duration" value="120">
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-generate btn-lg w-100"
                                        id="btn-generate-transcript"
                                        style="font-size: 1.2rem; padding: 1rem; background: #2563eb; color: white; border: 2px solid #2563eb;">
                                        <i class="fas fa-magic me-2"></i>Generate Transcript
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div id="result-transcript" style="display:none;">
                            <div class="content-card mt-4">
                                <h4>Step 2: Save Audio & Transcript</h4>
                                <form id="form-save-audio" enctype="multipart/form-data">
                                    <div class="mb-3">
                                        <label class="form-label">Title</label>
                                        <input type="text" class="form-control" name="title" id="title-passage"
                                            required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Transcript</label>
                                        <textarea class="form-control" name="transcript" id="transcript-passage"
                                            rows="10" required></textarea>
                                    </div>
                                    <div class="alert alert-info">
                                        <h5 class="alert-heading"><i class="fas fa-volume-up me-2"></i>Create Audio File
                                        </h5>
                                        <p>Your transcript has been generated. Now, create the audio file:</p>
                                        <ol>
                                            <li>Copy the transcript text from the field above.</li>
                                            <li>Go to a free Text-to-Speech service like <a href="https://freetts.com/"
                                                    target="_blank">freetts.com</a>.</li>
                                            <li>Paste the transcript, generate, and download the MP3 audio file.</li>
                                            <li>Upload the downloaded audio file below.</li>
                                        </ol>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Upload Audio File (.mp3, .wav, .ogg)</label>
                                        <input type="file" class="form-control" name="audio_file"
                                            accept=".mp3,.wav,.ogg" required>
                                    </div>
                                    <input type="hidden" name="duration">
                                    <input type="hidden" name="difficulty">
                                    <input type="hidden" name="listening_type" id="listening_type_hidden">
                                    <button type="submit" class="btn btn-save mt-3">
                                        <i class="fas fa-save me-2"></i>Save Audio & Transcript
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Generate Questions (Kept for existing passages) -->
                    <div class="tab-pane fade" id="questions" role="tabpanel">
                        <div class="content-card">
                            <h4>Generate Questions for an Existing Passage</h4>
                            <form id="form-generate-soal-existing">
                                <input type="hidden" name="id_audio" value="">
                                <div class="row mb-3">
                                    <div class="col-md-5">
                                        <label class="form-label">Select Listening Passage</label>
                                        <select class="form-select" name="id_audio_select" required>
                                            <option value="">-- Select Audio --</option>
                                            <?php foreach ($all_audio as $audio): ?>
                                                <option value="<?php echo $audio['id_audio']; ?>">
                                                    <?php echo htmlspecialchars($audio['judul']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Additional Prompt</label>
                                        <input type="text" class="form-control" name="prompt"
                                            placeholder="Example: Focus on specific details">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Number of Questions</label>
                                        <input type="number" class="form-control" name="jumlah" min="1" max="10"
                                            value="5" required>
                                    </div>
                                </div>
                        </div>
                        <div class="mt-3">
                            <button type="button" class="btn btn-generate btn-lg w-100" id="btn-generate-soal-existing"
                                style="font-size: 1.2rem; padding: 1rem; background: #2563eb; color: white; border: 2px solid #2563eb;">
                                <i class="fas fa-magic me-2"></i>Generate Questions
                            </button>
                        </div>
                        </form>
                        <div id="result-soal-existing" style="display:none;">
                            <form id="form-save-soal-existing">
                                <div id="list-soal-existing"></div>
                                <button type="submit" class="btn btn-save mt-3"><i class="fas fa-save me-2"></i>Save
                                    All Questions</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        function showNotification(message, type = 'info') {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });
            Toast.fire({ icon: type, title: message });
        }

        // Handle Transcript Generation
        document.getElementById('form-generate-transcript').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = document.getElementById('btn-generate-transcript');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

            const formData = new FormData(this);

            fetch('ajax_generate_transcript.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('title-passage').value = data.title;
                        document.getElementById('transcript-passage').value = data.transcript;
                        document.querySelector('#form-save-audio [name="duration"]').value = document.querySelector('#form-generate-transcript [name="duration"]').value;
                        document.querySelector('#form-save-audio [name="difficulty"]').value = document.querySelector('#form-generate-transcript [name="difficulty"]').value;
                        document.querySelector('#form-save-audio [name="listening_type"]').value = document.querySelector('#form-generate-transcript [name="listening_type"]').value;
                        document.getElementById('result-transcript').style.display = 'block';
                        showNotification('Transcript generated successfully!', 'success');
                    } else {
                        showNotification(data.error || 'An unknown error occurred.', 'error');
                    }
                })
                .catch(err => {
                    console.error('Fetch Error:', err);
                    showNotification('Network error: ' + err.message, 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });

        // Save Audio & Transcript, then reload
        document.getElementById('form-save-audio').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            // Set the listening_type from the first form
            document.getElementById('listening_type_hidden').value = document.querySelector('#form-generate-transcript [name="listening_type"]').value;

            const formData = new FormData(this);

            fetch('ajax_save_passage.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Audio and transcript saved successfully! Reloading page...', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(data.error || 'Failed to save audio.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    }
                })
                .catch(err => {
                    showNotification('A network error occurred. Please try again.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });

        function displayGeneratedQuestions(soal, targetElementId) {
            let html = '';
            soal.forEach((q, i) => {
                html += `
                    <div class="question-item">
                        <h6>Question ${i + 1}</h6>
                        <div class="mb-3">
                            <label class="form-label">Question</label>
                            <textarea class="form-control" name="pertanyaan[]" rows="3" required>${q.pertanyaan || ''}</textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2"><label>Option A</label><input class="form-control" name="opsi_a[]" value="${q.opsi_a || ''}" required></div>
                            <div class="col-md-6 mb-2"><label>Option B</label><input class="form-control" name="opsi_b[]" value="${q.opsi_b || ''}" required></div>
                            <div class="col-md-6 mb-2"><label>Option C</label><input class="form-control" name="opsi_c[]" value="${q.opsi_c || ''}" required></div>
                            <div class="col-md-6 mb-2"><label>Option D</label><input class="form-control" name="opsi_d[]" value="${q.opsi_d || ''}" required></div>
                        </div>
                        <div class="mt-2">
                            <label>Correct Answer</label>
                            <select class="form-select" name="jawaban_benar[]" required>
                                <option value="A" ${q.jawaban_benar === 'A' ? 'selected' : ''}>A</option>
                                <option value="B" ${q.jawaban_benar === 'B' ? 'selected' : ''}>B</option>
                                <option value="C" ${q.jawaban_benar === 'C' ? 'selected' : ''}>C</option>
                                <option value="D" ${q.jawaban_benar === 'D' ? 'selected' : ''}>D</option>
                            </select>
                        </div>
                    </div>
                `;
            });
            document.getElementById(targetElementId).innerHTML = html;
        }

        // Handle Question Generation for EXISTING passages (Tab 2)
        document.querySelector('[name="id_audio_select"]').addEventListener('change', function () {
            document.querySelector('#form-generate-soal-existing input[name="id_audio"]').value = this.value;
        });

        document.getElementById('btn-generate-soal-existing').addEventListener('click', function () {
            const form = document.getElementById('form-generate-soal-existing');
            const formData = new FormData(form);
            const btn = this;
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            fetch('ajax_generate_soal_listening.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.soal && data.soal.length > 0) {
                        displayGeneratedQuestions(data.soal, 'list-soal-existing');
                        document.getElementById('result-soal-existing').style.display = 'block';

                        const saveForm = document.getElementById('form-save-soal-existing');
                        // Ensure the hidden input for id_audio exists and has the correct value
                        let audioIdInput = saveForm.querySelector('input[name="id_audio"]');
                        if (!audioIdInput) {
                            audioIdInput = document.createElement('input');
                            audioIdInput.type = 'hidden';
                            audioIdInput.name = 'id_audio';
                            saveForm.appendChild(audioIdInput);
                        }
                        audioIdInput.value = form.querySelector('[name="id_audio_select"]').value;

                        showNotification('Questions generated successfully!', 'success');
                    } else {
                        showNotification(data.error || 'Failed to generate questions.', 'error');
                    }
                })
                .catch(err => showNotification('Network error. Please try again.', 'error'))
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });


        // Handle Save All Questions (for EXISTING flow in Tab 2)
        document.getElementById('form-save-soal-existing').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData(this);

            fetch('ajax_save_soal_listening.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        document.getElementById('result-soal-existing').style.display = 'none';
                        document.getElementById('form-generate-soal-existing').reset();
                    } else {
                        showNotification(data.error || 'An unknown error occurred.', 'error');
                    }
                })
                .catch(err => showNotification('Network error. Please try again.', 'error'))
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });

        // Check API Status
        document.getElementById('check-api-status').addEventListener('click', function () {
            const btn = this;
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Checking...';

            fetch('check_groq_status.php')
                .then(response => response.json())
                .then(data => {
                    const alertDiv = document.getElementById('api-status-alert');
                    const contentDiv = document.getElementById('api-status-content');

                    if (data.success) {
                        alertDiv.className = 'alert alert-success alert-dismissible fade show';
                        contentDiv.innerHTML = `
                            <h5><i class="fas fa-check-circle me-2"></i>API Status: Healthy</h5>
                            <p><strong>Provider:</strong> ${data.provider}<br>
                            <strong>Status:</strong> ${data.message}</p>
                        `;
                    } else {
                        alertDiv.className = 'alert alert-warning alert-dismissible fade show';
                        let content = `
                            <h5><i class="fas fa-exclamation-triangle me-2"></i>API Status: Issues Detected</h5>
                            <p><strong>Provider:</strong> ${data.provider}<br>
                            <strong>Error:</strong> ${data.error}</p>
                        `;

                        if (data.alternatives && data.alternatives.length > 0) {
                            content += `
                                <hr>
                                <h6>Available Alternatives:</h6>
                                <div class="d-flex gap-2 flex-wrap">
                            `;
                            data.alternatives.forEach(alt => {
                                content += `
                                    <button class="btn btn-sm btn-outline-primary switch-api" 
                                            data-setting="${alt.setting_key}">
                                        Switch to ${alt.provider} (${alt.model})
                                    </button>
                                `;
                            });
                            content += '</div>';
                        } else {
                            content += `
                                <hr>
                                <p class="mb-0"><i class="fas fa-info-circle me-2"></i>
                                No alternative APIs are configured. Please check your API settings or try again later.</p>
                            `;
                        }

                        contentDiv.innerHTML = content;

                        // Add event listeners for switch buttons
                        document.querySelectorAll('.switch-api').forEach(switchBtn => {
                            switchBtn.addEventListener('click', function () {
                                const settingKey = this.dataset.setting;
                                switchToAlternativeAPI(settingKey);
                            });
                        });
                    }

                    alertDiv.style.display = 'block';
                })
                .catch(err => {
                    const alertDiv = document.getElementById('api-status-alert');
                    const contentDiv = document.getElementById('api-status-content');

                    alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                    contentDiv.innerHTML = `
                        <h5><i class="fas fa-times-circle me-2"></i>Connection Error</h5>
                        <p>Unable to check API status: ${err.message}</p>
                    `;
                    alertDiv.style.display = 'block';
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });

        function switchToAlternativeAPI(settingKey) {
            const formData = new FormData();
            formData.append('new_active_api', settingKey);

            fetch('switch_api.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Successfully switched to ' + data.provider + '!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification('Failed to switch API: ' + data.error, 'error');
                    }
                })
                .catch(err => {
                    showNotification('Error switching API: ' + err.message, 'error');
                });
        }
    </script>
</body>

</html>