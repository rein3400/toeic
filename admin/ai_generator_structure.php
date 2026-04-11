<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - AI Generator Structure</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
    <style>
        .wysiwyg-toolbar {
            border: 1px solid var(--border-color);
            border-bottom: none;
            padding: 1rem;
            background: rgba(255,255,255,0.03);
            border-radius: 12px 12px 0 0;
        }

        .wysiwyg-toolbar button {
            margin-right: 0.5rem;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            background: var(--surface);
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            transition: all 0.3s ease;
        }

        .wysiwyg-toolbar button:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .wysiwyg-editor {
            border: 1px solid var(--border-color);
            padding: 1.5rem;
            min-height: 150px;
            border-radius: 0 0 12px 12px;
            background-color: rgba(255,255,255,0.05);
            color: var(--text-main);
            font-size: 1rem;
            line-height: 1.6;
        }

        .wysiwyg-editor:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .question-block {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .question-block:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .question-block h6 {
            color: var(--primary-hover);
            font-weight: bold;
            font-size: 1.2rem;
        }

        .question-content {
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 1rem;
            color: var(--text-main);
            background: var(--primary-light);
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .generator-form {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 2rem;
        }

        .btn-generate {
            background: var(--primary);
            border: none;
            color: white;
            padding: 1rem 2rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px var(--primary-glow);
        }

        .btn-generate:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px var(--primary-glow);
            color: white;
        }

        .btn-save {
            background: var(--success);
            border: none;
            color: white;
            padding: 1rem 2rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .btn-save:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(16,185,129,0.3);
            color: white;
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
                        <h1><i class="fas fa-spell-check me-3"></i>AI Generator Structure</h1>
                        <div class="text-end">
                            <a href="manage_structure.php" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Back to Structure
                            </a>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <h4><i class="fas fa-magic me-2"></i>Generate Structure Questions</h4>
                    <div class="generator-form">
                        <form id="form-generate-soal">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Topic/Theme</label>
                                    <input type="text" class="form-control" name="prompt"
                                        placeholder="e.g., Subjunctive mood, parallel structure, verb tenses">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Difficulty</label>
                                    <select class="form-select" name="difficulty">
                                        <option value="easy">Easy</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="hard">Hard</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Number of Questions</label>
                                    <input type="number" class="form-control" name="jumlah" min="1" max="20" value="5"
                                        required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Additional Instructions (Optional)</label>
                                <div class="wysiwyg-toolbar">
                                    <button type="button" class="btn btn-light" onclick="formatDoc('bold')"><i
                                            class="fas fa-bold"></i></button>
                                    <button type="button" class="btn btn-light" onclick="formatDoc('italic')"><i
                                            class="fas fa-italic"></i></button>
                                    <button type="button" class="btn btn-light" onclick="formatDoc('underline')"><i
                                            class="fas fa-underline"></i></button>
                                    <button type="button" class="btn btn-light"
                                        onclick="formatDoc('insertUnorderedList')"><i
                                            class="fas fa-list-ul"></i></button>
                                    <button type="button" class="btn btn-light"
                                        onclick="formatDoc('insertOrderedList')"><i class="fas fa-list-ol"></i></button>
                                </div>
                                <div id="editor" class="wysiwyg-editor" contenteditable="true"></div>
                                <textarea name="instructions" id="instructions" style="display:none;"></textarea>
                            </div>
                            <button type="submit" class="btn btn-generate" id="btn-generate-soal">
                                <i class="fas fa-magic me-2"></i>Generate Questions
                            </button>
                        </form>
                    </div>
                </div>

                <div id="result-soal" style="display:none;">
                    <div class="content-card mt-4">
                        <h4>Generated Questions Preview</h4>
                        <div id="list-soal"></div>
                        <div class="mt-4 text-center">
                            <button type="button" class="btn btn-save" id="btn-save-questions">
                                <i class="fas fa-save me-2"></i>Save All Questions to Database
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        let currentGeneratedQuestions = [];

        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });

        function showNotification(message, type = 'info') {
            Toast.fire({ icon: type, title: message });
        }

        function formatDoc(command, value = null) {
            document.execCommand(command, false, value);
        }

        const editor = document.getElementById('editor');
        const instructionsTextarea = document.getElementById('instructions');

        editor.addEventListener('input', () => {
            instructionsTextarea.value = editor.innerHTML;
        });

        document.getElementById('form-generate-soal').addEventListener('submit', function (e) {
            e.preventDefault();
            instructionsTextarea.value = editor.innerHTML;

            const btn = document.getElementById('btn-generate-soal');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

            fetch('ajax_generate_soal_structure.php', {
                method: 'POST',
                body: new FormData(this)
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.soal) {
                        displayGeneratedQuestions(data.soal);
                        document.getElementById('result-soal').style.display = 'block';
                        showNotification('Questions generated successfully!', 'success');
                    } else {
                        showNotification(data.error || 'An unknown error occurred.', 'error');
                    }
                })
                .catch(err => {
                    console.error('Generation error:', err);
                    showNotification('Network error. Please try again.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });

        function displayGeneratedQuestions(soal) {
            currentGeneratedQuestions = soal;

            if (!soal || soal.length === 0) {
                document.getElementById('list-soal').innerHTML = '<div class="alert alert-warning">No questions generated. Please try again.</div>';
                return;
            }

            const html = soal.map((q, i) => {
                // Check for required fields - backend sends jawaban_benar, not jawaban
                if (!q.pertanyaan || !q.jawaban_benar) {
                    return `
                        <div class="question-block">
                            <h6 class="text-danger">Question ${i + 1} - Invalid Data</h6>
                            <p class="text-muted">This question contains incomplete data. Missing: ${!q.pertanyaan ? 'pertanyaan ' : ''}${!q.jawaban_benar ? 'jawaban_benar' : ''}</p>
                            <pre class="small text-muted">${JSON.stringify(q, null, 2)}</pre>
                        </div>
                    `;
                }

                return `
                    <div class="question-block">
                        <h6 class="text-primary mb-3">Question ${i + 1}</h6>
                        <div class="question-content mb-3">${q.pertanyaan}</div>
                        <div class="mb-2">
                            <div><strong>A)</strong> ${q.opsi_a || '-'}</div>
                            <div><strong>B)</strong> ${q.opsi_b || '-'}</div>
                            <div><strong>C)</strong> ${q.opsi_c || '-'}</div>
                            <div><strong>D)</strong> ${q.opsi_d || '-'}</div>
                        </div>
                        <p><strong>Correct Answer:</strong> ${q.jawaban_benar}</p>
                        <p><em>Explanation:</em> ${q.penjelasan || 'No explanation provided.'}</p>
                    </div>
                `;
            }).join('');

            document.getElementById('list-soal').innerHTML = html;
        }

        document.getElementById('btn-save-questions').addEventListener('click', function () {
            if (!currentGeneratedQuestions || currentGeneratedQuestions.length === 0) {
                showNotification('No questions to save.', 'error');
                return;
            }

            const btn = this;
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData();

            currentGeneratedQuestions.forEach((q) => {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = q.pertanyaan;
                const plainTextQuestion = tempDiv.textContent || tempDiv.innerText || '';

                formData.append('pertanyaan[]', plainTextQuestion);
                formData.append('opsi_a[]', q.opsi_a || 'Option A');
                formData.append('opsi_b[]', q.opsi_b || 'Option B');
                formData.append('opsi_c[]', q.opsi_c || 'Option C');
                formData.append('opsi_d[]', q.opsi_d || 'Option D');
                formData.append('jawaban_benar[]', q.jawaban_benar || 'A');
                formData.append('penjelasan[]', q.penjelasan || '');
            });

            fetch('ajax_save_soal_structure.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        document.getElementById('result-soal').style.display = 'none';
                        document.getElementById('form-generate-soal').reset();
                        editor.innerHTML = '';
                        currentGeneratedQuestions = [];
                    } else {
                        showNotification(data.error || 'Failed to save questions.', 'error');
                    }
                })
                .catch(err => {
                    console.error('Save error:', err);
                    showNotification('Network error. Please try again.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });
    </script>
</body>

</html>