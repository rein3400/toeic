<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
// Ambil passages untuk tab generate soal
$passages = $conn->query("SELECT * FROM teks_bacaan ORDER BY id_teks DESC");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrfMeta() ?>
    <title><?php echo htmlspecialchars($website_title); ?> - AI Generator Reading</title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .wysiwyg-toolbar {
            border: 1px solid var(--border-color);
            border-bottom: none;
            border-radius: 12px 12px 0 0;
            background: rgba(255,255,255,0.03);
            padding: 1rem;
        }

        .wysiwyg-toolbar button {
            border: 1px solid var(--border-color);
            background: var(--surface);
            color: var(--text-secondary);
            padding: 0.5rem 0.75rem;
            margin: 0 0.25rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .wysiwyg-toolbar button:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .wysiwyg-toolbar button.active {
            background: var(--primary-dark);
            color: white;
            border-color: var(--primary-dark);
        }

        .wysiwyg-editor {
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 12px 12px;
            min-height: 120px;
            padding: 1.5rem;
            background: rgba(255,255,255,0.05);
            color: var(--text-main);
            overflow-y: auto;
            font-size: 1rem;
            line-height: 1.6;
        }

        .wysiwyg-editor:focus {
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .wysiwyg-editor:empty:before {
            content: attr(data-placeholder);
            color: var(--text-faint);
            font-style: italic;
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
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-9 col-lg-10 admin-content">
                <div class="admin-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h1><i class="fas fa-magic me-3"></i>AI Generator Reading</h1>
                        <div class="text-end">
                            <a href="manage_reading.php" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Back to Reading
                            </a>
                        </div>
                    </div>
                </div>
                <ul class="nav nav-tabs mb-4" id="aiGenTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="passage-tab" data-bs-toggle="tab" data-bs-target="#passage"
                            type="button" role="tab">Generate Passage</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="soal-tab" data-bs-toggle="tab" data-bs-target="#soal" type="button"
                            role="tab">Generate Questions</button>
                    </li>
                </ul>
                <div class="tab-content">
                    <!-- Tab 1: Generate Passage -->
                    <div class="tab-pane fade show active" id="passage" role="tabpanel">
                        <div class="content-card">
                            <h4><i class="fas fa-book me-2"></i>Generate Reading Passage</h4>
                            <form id="form-generate-passage">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Prompt</label>
                                        <div class="wysiwyg-toolbar">
                                            <button type="button" onclick="formatText('bold')" title="Bold"><i
                                                    class="fas fa-bold"></i></button>
                                            <button type="button" onclick="formatText('italic')" title="Italic"><i
                                                    class="fas fa-italic"></i></button>
                                            <button type="button" onclick="formatText('underline')" title="Underline"><i
                                                    class="fas fa-underline"></i></button>
                                            <button type="button" onclick="formatText('insertUnorderedList')"
                                                title="Bullet List"><i class="fas fa-list-ul"></i></button>
                                            <button type="button" onclick="formatText('insertOrderedList')"
                                                title="Numbered List"><i class="fas fa-list-ol"></i></button>
                                            <button type="button" onclick="formatText('justifyLeft')"
                                                title="Align Left"><i class="fas fa-align-left"></i></button>
                                            <button type="button" onclick="formatText('justifyCenter')"
                                                title="Align Center"><i class="fas fa-align-center"></i></button>
                                            <button type="button" onclick="formatText('justifyRight')"
                                                title="Align Right"><i class="fas fa-align-right"></i></button>
                                        </div>
                                        <div class="wysiwyg-editor" contenteditable="true" id="prompt-editor"
                                            data-placeholder="Example: Reading about climate change focusing on impacts on marine ecosystems...">
                                        </div>
                                        <input type="hidden" name="prompt" id="prompt-hidden">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Difficulty Level</label>
                                        <select class="form-select" name="difficulty">
                                            <option value="easy">Easy</option>
                                            <option value="medium">Medium</option>
                                            <option value="hard">Hard</option>
                                        </select>
                                    </div>
                                </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <button type="button" class="btn btn-generate btn-lg w-100" id="btn-generate-passage"
                                    style="font-size: 1.2rem; padding: 1rem; background: #2563eb; color: white; border: 2px solid #2563eb;">
                                    <i class="fas fa-magic me-2"></i>Generate Passage
                                </button>
                            </div>
                        </div>
                        </form>
                        <div id="result-passage" style="display:none;">
                            <form id="form-save-passage">
                                <div class="mb-3">
                                    <label class="form-label">Article Title</label>
                                    <input type="text" class="form-control" name="judul" id="judul-passage" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Article Content</label>
                                    <textarea class="form-control" name="isi_teks" id="isi-passage" rows="10"
                                        required></textarea>
                                </div>
                                <button type="submit" class="btn btn-save"><i class="fas fa-save me-2"></i>Save to
                                    Passage</button>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- Tab 2: Generate Questions -->
                <div class="tab-pane fade" id="soal" role="tabpanel">
                    <div class="content-card">
                        <h4><i class="fas fa-question-circle me-2"></i>Generate Questions from Passage</h4>
                        <form id="form-generate-soal">
                            <div class="row mb-3">
                                <div class="col-md-5">
                                    <label class="form-label">Select Passage</label>
                                    <select class="form-select" name="id_teks" required>
                                        <option value="">-- Select Passage --</option>
                                        <?php if ($passages) {
                                            $passages->data_seek(0);
                                            while ($p = $passages->fetch_assoc()): ?>
                                                <option value="<?php echo $p['id_teks']; ?>">
                                                    <?php echo htmlspecialchars($p['judul']); ?>
                                                </option>
                                            <?php endwhile;
                                        } ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Additional Prompt</label>
                                    <input type="text" class="form-control" name="prompt"
                                        placeholder="Example: Focus on main ideas">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Number of Questions</label>
                                    <input type="number" class="form-control" name="jumlah" min="1" max="10" value="5"
                                        required>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <button type="button" class="btn btn-generate btn-lg w-100" id="btn-generate-soal"
                                        style="font-size: 1.2rem; padding: 1rem; background: #2563eb; color: white; border: 2px solid #2563eb;">
                                        <i class="fas fa-magic me-2"></i>Generate Questions
                                    </button>
                                </div>
                            </div>
                        </form>
                        <div id="result-soal" style="display:none;">
                            <form id="form-save-soal">
                                <div id="list-soal"></div>
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
        // WYSIWYG Editor Functions
        function formatText(command) {
            document.execCommand(command, false, null);
            updatePromptValue();
        }

        function updatePromptValue() {
            const editor = document.getElementById('prompt-editor');
            const hiddenInput = document.getElementById('prompt-hidden');
            hiddenInput.value = editor.innerHTML;
        }

        // Initialize editor
        document.getElementById('prompt-editor').addEventListener('input', updatePromptValue);

        // Passage Generation
        document.getElementById('btn-generate-passage').onclick = function () {
            // Update hidden input before submitting
            updatePromptValue();

            var form = document.getElementById('form-generate-passage');
            var data = new FormData(form);
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

            fetch('ajax_generate_reading.php', {
                method: 'POST',
                body: data
            }).then(r => r.json()).then(res => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-magic me-2"></i>Generate';

                console.log('AI Response:', res); // Debug log

                if (res.success) {
                    document.getElementById('result-passage').style.display = '';

                    // Only populate title and content (topic removed)
                    document.getElementById('judul-passage').value = res.judul || 'Generated Reading Passage';
                    document.getElementById('isi-passage').value = res.isi_teks || 'No content generated';

                    // Show success notification
                    showNotification('Passage generated successfully!', 'success');
                } else {
                    showNotification(res.error || 'Failed to generate passage', 'error');
                }
            }).catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-magic me-2"></i>Generate';
                console.error('Generation error:', err);
                showNotification('Network error occurred', 'error');
            });
        };

        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} alert-dismissible fade show`;
            notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

            // Insert at the top of the content
            const container = document.querySelector('.col-md-9.col-lg-10.admin-content');
            if (container) {
                container.prepend(notification);
            } else {
                // Fallback
                document.body.prepend(notification);
            }

            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Save Passage
        document.getElementById('form-save-passage').onsubmit = function (e) {
            e.preventDefault();
            var data = new FormData(this);
            fetch('ajax_save_reading_passage.php', { method: 'POST', body: data })
                .then(r => r.json()).then(res => {
                    if (res.success) {
                        showNotification('Passage saved successfully! Reloading page...', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(res.error || 'Failed to save passage', 'error');
                    }
                });
        };

        // Soal Generation
        document.getElementById('btn-generate-soal').onclick = function () {
            var form = document.getElementById('form-generate-soal');
            var data = new FormData(form);
            var btn = this;
            var jumlahInput = document.querySelector('input[name="jumlah"]').value;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            fetch('ajax_generate_soal_reading.php', {
                method: 'POST',
                body: data
            }).then(r => r.json()).then(res => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-magic"></i>';

                console.log('Question Response:', res); // Debug log

                if (res.success && res.soal && res.soal.length) {
                    document.getElementById('result-soal').style.display = '';
                    var html = '';

                    // Show generation info
                    html += `<div class='alert alert-info mb-3'>
                    <i class="fas fa-info-circle me-2"></i>
                    Generated ${res.soal.length} questions (requested: ${jumlahInput})
                    ${res.jumlah_generated ? ` - AI returned: ${res.jumlah_generated}` : ''}
                </div>`;

                    res.soal.forEach(function (q, i) {
                        html += `<div class='mb-4 border rounded p-3 question-item' data-question='${i + 1}'>
                        <div class='d-flex justify-content-between align-items-center mb-2'>
                            <h6 class='mb-0 text-primary'>Question #${i + 1}</h6>
                            <button type='button' class='btn btn-sm btn-outline-danger' onclick='removeQuestion(this)'>
                                <i class='fas fa-trash'></i> Delete
                            </button>
                        </div>
                        <div class='mb-3'>
                            <label class='form-label'>Question</label>
                            <textarea class='form-control' name='pertanyaan[]' rows='3' required>${q.pertanyaan || ''}</textarea>
                        </div>
                        <div class='row mb-3'>
                            <div class='col-md-6 mb-2'>
                                <label class='form-label'>Option A</label>
                                <input class='form-control' name='opsi_a[]' value='${q.opsi_a || ''}' placeholder='Enter option A' required>
                            </div>
                            <div class='col-md-6 mb-2'>
                                <label class='form-label'>Option B</label>
                                <input class='form-control' name='opsi_b[]' value='${q.opsi_b || ''}' placeholder='Enter option B' required>
                            </div>
                            <div class='col-md-6 mb-2'>
                                <label class='form-label'>Option C</label>
                                <input class='form-control' name='opsi_c[]' value='${q.opsi_c || ''}' placeholder='Enter option C' required>
                            </div>
                            <div class='col-md-6 mb-2'>
                                <label class='form-label'>Option D</label>
                                <input class='form-control' name='opsi_d[]' value='${q.opsi_d || ''}' placeholder='Enter option D' required>
                            </div>
                        </div>
                        <div class='row'>
                            <div class='col-md-6'>
                                <label class='form-label'>Correct Answer</label>
                                <select class='form-select' name='jawaban_benar[]' required>
                                    <option value='A' ${q.jawaban_benar === 'A' ? 'selected' : ''}>A</option>
                                    <option value='B' ${q.jawaban_benar === 'B' ? 'selected' : ''}>B</option>
                                    <option value='C' ${q.jawaban_benar === 'C' ? 'selected' : ''}>C</option>
                                    <option value='D' ${q.jawaban_benar === 'D' ? 'selected' : ''}>D</option>
                                </select>
                            </div>
                        </div>
                    </div>`;
                    });

                    // Add button to add more questions
                    html += `<div class='mb-3 text-center'>
                    <button type='button' class='btn btn-outline-primary' onclick='addNewQuestion()'>
                        <i class='fas fa-plus me-2'></i>Add New Question
                    </button>
                </div>`;

                    document.getElementById('list-soal').innerHTML = html;
                    showNotification(`${res.soal.length} questions generated successfully!`, 'success');
                } else {
                    showNotification(res.error || 'Failed to generate questions', 'error');
                }
            }).catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-magic"></i>';
                console.error('Question generation error:', err);
                showNotification('Network error occurred', 'error');
            });
        };

        // Function to remove question
        function removeQuestion(button) {
            const questionItem = button.closest('.question-item');
            if (questionItem) {
                questionItem.remove();
                // Renumber remaining questions
                renumberQuestions();
            }
        }

        // Function to add new question
        function addNewQuestion() {
            const listSoal = document.getElementById('list-soal');
            const questionCount = document.querySelectorAll('.question-item').length + 1;

            const newQuestionHtml = `<div class='mb-4 border rounded p-3 question-item' data-question='${questionCount}'>
            <div class='d-flex justify-content-between align-items-center mb-2'>
                <h6 class='mb-0 text-primary'>Question #${questionCount}</h6>
                <button type='button' class='btn btn-sm btn-outline-danger' onclick='removeQuestion(this)'>
                    <i class='fas fa-trash'></i> Delete
                </button>
            </div>
            <div class='mb-3'>
                <label class='form-label'>Question</label>
                <textarea class='form-control' name='pertanyaan[]' rows='3' placeholder='Enter question...' required></textarea>
            </div>
            <div class='row mb-3'>
                <div class='col-md-6 mb-2'>
                    <label class='form-label'>Option A</label>
                    <input class='form-control' name='opsi_a[]' placeholder='Enter option A' required>
                </div>
                <div class='col-md-6 mb-2'>
                    <label class='form-label'>Option B</label>
                    <input class='form-control' name='opsi_b[]' placeholder='Enter option B' required>
                </div>
                <div class='col-md-6 mb-2'>
                    <label class='form-label'>Option C</label>
                    <input class='form-control' name='opsi_c[]' placeholder='Enter option C' required>
                </div>
                <div class='col-md-6 mb-2'>
                    <label class='form-label'>Option D</label>
                    <input class='form-control' name='opsi_d[]' placeholder='Enter option D' required>
                </div>
            </div>
            <div class='row'>
                <div class='col-md-6'>
                    <label class='form-label'>Correct Answer</label>
                    <select class='form-select' name='jawaban_benar[]' required>
                        <option value='A'>A</option>
                        <option value='B'>B</option>
                        <option value='C'>C</option>
                        <option value='D'>D</option>
                    </select>
                </div>
            </div>
        </div>`;

            // Insert before the add button
            const addButton = listSoal.querySelector('button[onclick="addNewQuestion()"]').parentElement;
            addButton.insertAdjacentHTML('beforebegin', newQuestionHtml);
        }

        // Function to renumber questions
        function renumberQuestions() {
            const questionItems = document.querySelectorAll('.question-item');
            questionItems.forEach((item, index) => {
                const questionNum = index + 1;
                item.setAttribute('data-question', questionNum);
                item.querySelector('h6').textContent = `Question #${questionNum}`;
            });
        }

        // Save Soal
        document.getElementById('form-save-soal').onsubmit = function (e) {
            e.preventDefault();
            var soalForm = this;
            var data = new FormData(soalForm);
            var id_teks = document.querySelector('#form-generate-soal select[name="id_teks"]').value;
            data.append('id_teks', id_teks);
            data.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            fetch('ajax_save_soal_reading.php', { method: 'POST', body: data })
                .then(r => r.json()).then(res => {
                    if (res.success) {
                        showNotification('Questions saved successfully! Reloading page...', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(res.error || 'Failed to save questions', 'error');
                    }
                });
        };
    </script>
</body>

</html>
