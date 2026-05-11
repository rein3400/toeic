(function () {
    const cfg = window.TOEIC_SW_CONFIG || {};
    const csrfToken = cfg.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const saveTimers = new Map();
    const pendingUploads = new Map();
    const uploadErrors = new Map();
    const recorders = new Map();
    const recordingStreams = new Map();
    const prepareTimers = new Map();

    function setStatus(rowId, text, state) {
        const el = document.getElementById(`sw-status-${rowId}`);
        if (!el) {
            return;
        }
        el.textContent = text;
        el.dataset.state = state || '';
        el.className = `sw-status ${state || ''}`.trim();
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify(Object.assign({csrf_token: csrfToken}, payload)),
        });
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.error || data.message || 'Request failed');
        }
        return data;
    }

    function countWords(text) {
        const words = String(text || '').trim().split(/\s+/).filter(Boolean);
        return words.length;
    }

    window.persistToeicSwAnswer = async function persistToeicSwAnswer(rowId, answer) {
        setStatus(rowId, 'Saving...', 'pending');
        const data = await postJson('ajax_save_toeic_sw_answer.php', {
            test_session: cfg.testSession,
            section: cfg.section,
            question_row_id: rowId,
            answer: answer,
        });
        setStatus(rowId, 'Saved', 'saved');
        return data;
    };

    function queueTextSave(textarea) {
        const rowId = textarea.dataset.rowId;
        const counter = document.getElementById(`sw-word-count-${rowId}`);
        if (counter) {
            counter.textContent = `${countWords(textarea.value)} words`;
        }
        if (saveTimers.has(rowId)) {
            clearTimeout(saveTimers.get(rowId));
        }
        saveTimers.set(rowId, setTimeout(() => {
            window.persistToeicSwAnswer(rowId, textarea.value).catch((error) => {
                setStatus(rowId, error.message || 'Save failed', 'error');
            });
        }, 650));
    }

    async function uploadRecording(rowId, blob) {
        pendingUploads.set(String(rowId), true);
        uploadErrors.delete(String(rowId));
        setStatus(rowId, 'Uploading recording...', 'pending');

        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('test_session', cfg.testSession);
        form.append('section', cfg.section);
        form.append('question_row_id', rowId);
        form.append('recording', blob, `toeic-sw-${rowId}.webm`);

        try {
            const response = await fetch('ajax_save_toeic_sw_recording.php', {
                method: 'POST',
                body: form,
            });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Upload failed');
            }
            setStatus(rowId, 'Recording saved', 'saved');
            const audio = document.getElementById(`sw-playback-${rowId}`);
            if (audio) {
                audio.src = URL.createObjectURL(blob);
                audio.hidden = false;
            }
            const question = document.querySelector(`.sw-question[data-row-id="${rowId}"]`);
            if (question) {
                question.dataset.hasAnswer = '1';
            }
        } catch (error) {
            uploadErrors.set(String(rowId), error.message || 'Upload failed');
            setStatus(rowId, error.message || 'Upload failed', 'error');
        } finally {
            pendingUploads.delete(String(rowId));
        }
    }

    function stopRecorder(rowId) {
        const recorder = recorders.get(String(rowId));
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
    }

    window.startToeicSwPrepare = function startToeicSwPrepare(rowId, prepareSeconds, maxSeconds) {
        rowId = String(rowId);
        if (prepareTimers.has(rowId) || recorders.has(rowId)) {
            return;
        }

        const button = document.getElementById(`record-btn-${rowId}`);
        const timer = document.getElementById(`sw-record-timer-${rowId}`);
        let remaining = Math.max(0, Number(prepareSeconds || 0));

        const enableRecording = () => {
            prepareTimers.delete(rowId);
            if (button) {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-microphone me-2"></i>Start Recording';
                button.onclick = () => window.startToeicSwTimedRecording(rowId, maxSeconds);
            }
            if (timer) {
                timer.textContent = 'Ready';
            }
            setStatus(rowId, 'Preparation complete', 'saved');
        };

        if (remaining <= 0) {
            enableRecording();
            return;
        }

        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-hourglass-half me-2"></i>Preparing';
        }
        setStatus(rowId, 'Preparing...', 'pending');
        if (timer) {
            timer.textContent = `Prepare ${remaining}s`;
        }

        const interval = setInterval(() => {
            remaining--;
            if (timer) {
                timer.textContent = remaining > 0 ? `Prepare ${remaining}s` : 'Ready';
            }
            if (remaining <= 0) {
                clearInterval(interval);
                enableRecording();
            }
        }, 1000);
        prepareTimers.set(rowId, interval);
    };

    window.startToeicSwTimedRecording = async function startToeicSwTimedRecording(rowId, maxSeconds) {
        rowId = String(rowId);
        if (recorders.has(rowId)) {
            stopRecorder(rowId);
            return;
        }

        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({audio: true, video: false});
        } catch (error) {
            setStatus(rowId, 'Microphone permission is required', 'error');
            return;
        }

        const chunks = [];
        let remaining = Math.max(1, Number(maxSeconds || 30));
        const button = document.getElementById(`record-btn-${rowId}`);
        const timer = document.getElementById(`sw-record-timer-${rowId}`);
        const recorder = new MediaRecorder(stream);
        recorders.set(rowId, recorder);
        recordingStreams.set(rowId, stream);

        recorder.ondataavailable = (event) => {
            if (event.data && event.data.size > 0) {
                chunks.push(event.data);
            }
        };

        recorder.onstop = () => {
            const savedStream = recordingStreams.get(rowId);
            if (savedStream) {
                savedStream.getTracks().forEach(track => track.stop());
            }
            recorders.delete(rowId);
            recordingStreams.delete(rowId);
            if (button) {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-microphone me-2"></i>Record Again';
            }
            if (timer) {
                timer.textContent = '';
            }
            const blob = new Blob(chunks, {type: recorder.mimeType || 'audio/webm'});
            uploadRecording(rowId, blob);
        };

        if (button) {
            button.innerHTML = '<i class="fas fa-stop me-2"></i>Stop Recording';
        }
        setStatus(rowId, 'Recording...', 'pending');
        recorder.start();

        const interval = setInterval(() => {
            if (!recorders.has(rowId)) {
                clearInterval(interval);
                return;
            }
            if (timer) {
                timer.textContent = `${remaining}s`;
            }
            remaining--;
            if (remaining < 0) {
                clearInterval(interval);
                stopRecorder(rowId);
            }
        }, 1000);
    };

    window.waitForToeicSwRecordingSaves = async function waitForToeicSwRecordingSaves() {
        while (pendingUploads.size > 0) {
            await new Promise(resolve => setTimeout(resolve, 300));
        }
        if (uploadErrors.size > 0) {
            const firstError = Array.from(uploadErrors.values())[0];
            throw new Error(firstError || 'A recording upload failed');
        }
    };

    window.submitToeicSwSection = async function submitToeicSwSection() {
        const submit = document.getElementById('sw-submit-section');
        const message = document.getElementById('sw-submit-message');
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Submitting...';
        }
        if (message) {
            message.textContent = '';
            message.className = 'small text-muted';
        }

        try {
            document.querySelectorAll('textarea[data-row-id]').forEach((textarea) => {
                if (saveTimers.has(textarea.dataset.rowId)) {
                    clearTimeout(saveTimers.get(textarea.dataset.rowId));
                }
            });
            for (const textarea of document.querySelectorAll('textarea[data-row-id]')) {
                await window.persistToeicSwAnswer(textarea.dataset.rowId, textarea.value);
            }
            await window.waitForToeicSwRecordingSaves();
            if (cfg.section === 'speaking') {
                const missing = Array.from(document.querySelectorAll('.sw-question[data-section="speaking"][data-has-answer="0"]'));
                if (missing.length > 0) {
                    throw new Error(`Complete and upload all speaking recordings before submitting. Missing: ${missing.length}`);
                }
            }
            const data = await postJson('ajax_submit_section_toeic_sw.php', {
                test_session: cfg.testSession,
                section: cfg.section,
            });
            window.location.href = data.redirect || 'index.php';
        } catch (error) {
            if (message) {
                message.textContent = error.message || 'Submit failed';
                message.className = 'small text-danger fw-bold';
            }
            if (submit) {
                submit.disabled = false;
                submit.textContent = cfg.section === 'speaking' ? 'Submit Speaking' : 'Submit Writing';
            }
        }
    };

    function startSectionTimer() {
        const timer = document.getElementById('sw-section-timer');
        if (!timer || !cfg.sectionDeadline) {
            return;
        }
        const tick = () => {
            const remaining = Math.max(0, Number(cfg.sectionDeadline) - Math.floor(Date.now() / 1000));
            const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
            const seconds = String(remaining % 60).padStart(2, '0');
            timer.textContent = `${minutes}:${seconds}`;
            if (remaining <= 0) {
                clearInterval(interval);
                window.submitToeicSwSection();
            }
        };
        const interval = setInterval(tick, 1000);
        tick();
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('textarea[data-row-id]').forEach((textarea) => {
            textarea.addEventListener('input', () => queueTextSave(textarea));
            queueTextSave(textarea);
        });
        startSectionTimer();
    });
})();
