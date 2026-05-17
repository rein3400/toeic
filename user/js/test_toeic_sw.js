(function () {
    const cfg = window.TOEIC_SW_CONFIG || {};
    const csrfToken = cfg.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const saveTimers = new Map();
    const pendingTextSaves = new Map();
    const pendingUploads = new Map();
    const uploadErrors = new Map();
    const recorders = new Map();
    const recordingStreams = new Map();
    const recordingTimers = new Map();
    const prepareTimers = new Map();
    const taskTimerIntervals = new Map();
    const micRequests = new Set();
    const autoStartedRows = new Set();
    const AUTO_RECORD_GAP_SECONDS = 5;
    let currentQuestion = 0;
    let submitting = false;

    function rowKey(rowId) {
        return String(rowId);
    }

    function questionCards() {
        return Array.from(document.querySelectorAll('.sw-question[data-question]'));
    }

    function setStatus(rowId, text, state) {
        const el = document.getElementById(`sw-status-${rowId}`);
        if (!el) {
            return;
        }
        el.textContent = text;
        el.dataset.state = state || '';
        el.className = `sw-status ${state || ''}`.trim();
    }

    function setSubmitMessage(text, state) {
        const message = document.getElementById('sw-submit-message');
        if (!message) {
            return;
        }
        message.textContent = text || '';
        message.dataset.state = state || '';
        if (state === 'error') {
            message.className = 'small text-danger fw-bold';
        } else if (state === 'ok') {
            message.className = 'small text-success fw-bold';
        } else if (state === 'pending') {
            message.className = 'small text-warning fw-bold';
        } else {
            message.className = 'small text-muted';
        }
    }

    function getMissingSpeakingCount() {
        return document.querySelectorAll('.sw-question[data-section="speaking"][data-has-answer="0"]').length;
    }

    function updateProgressCount() {
        const counter = document.getElementById('sw-progress-count');
        if (!counter) {
            return;
        }
        const cards = questionCards();
        const answered = cards.filter((card) => card.dataset.hasAnswer === '1').length;
        counter.textContent = `${answered} / ${cards.length}`;
    }

    function isSpeakingFlowBusy() {
        return recorders.size > 0 || pendingUploads.size > 0 || prepareTimers.size > 0 || micRequests.size > 0;
    }

    function allowAutoFlowRetry(rowId) {
        autoStartedRows.delete(rowKey(rowId));
    }

    function refreshSubmitState() {
        const submit = document.getElementById('sw-submit-section');
        const cards = questionCards();
        const prev = document.getElementById('sw-prev-question');
        const next = document.getElementById('sw-next-question');
        const busyNav = cfg.section === 'speaking' && isSpeakingFlowBusy();
        if (prev) {
            prev.disabled = currentQuestion <= 0 || busyNav;
        }
        if (next) {
            next.disabled = currentQuestion >= cards.length - 1 || busyNav;
        }
        document.querySelectorAll('.sw-section-map [data-question-jump]').forEach((button) => {
            const target = Number(button.dataset.questionJump || 0);
            button.disabled = busyNav && target !== currentQuestion;
        });

        if (!submit) {
            return;
        }

        if (submitting) {
            submit.disabled = true;
            return;
        }

        if (cfg.section !== 'speaking') {
            submit.disabled = false;
            const message = document.getElementById('sw-submit-message');
            if (message && !message.dataset.state) {
                setSubmitMessage('Writing autosaves while you type.', '');
            }
            return;
        }

        const missing = getMissingSpeakingCount();
        const busy = isSpeakingFlowBusy();
        const failedUploads = uploadErrors.size;
        submit.disabled = missing > 0 || busy || failedUploads > 0;

        if (failedUploads > 0) {
            setSubmitMessage('A recording upload failed. Open that question again to retry automatically.', 'error');
        } else if (busy) {
            setSubmitMessage('Auto prepare, recording, or upload is still running. Next unlocks after save.', 'pending');
        } else if (missing > 0) {
            setSubmitMessage(`${missing} speaking question${missing === 1 ? '' : 's'} still need the automatic recording.`, '');
        } else {
            setSubmitMessage('All recordings uploaded. Ready to submit.', 'ok');
        }
    }

    function setOverlay(active) {
        const overlay = document.getElementById('sw-scoring-overlay');
        if (overlay) {
            overlay.classList.toggle('active', Boolean(active));
        }
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
        const data = await response.json().catch(() => ({
            success: false,
            error: `Invalid JSON response from ${url}`,
        }));
        if (!response.ok || !data.success) {
            throw new Error(data.error || data.message || `HTTP ${response.status}`);
        }
        return data;
    }

    function countWords(text) {
        return String(text || '').trim().split(/\s+/).filter(Boolean).length;
    }

    function setWordCount(textarea) {
        const rowId = textarea.dataset.rowId;
        const counter = document.getElementById(`sw-word-count-${rowId}`);
        if (counter) {
            counter.textContent = `${countWords(textarea.value)} words`;
        }
    }

    function markQuestionAnswered(rowId, answered) {
        const card = document.querySelector(`.sw-question[data-row-id="${rowId}"]`);
        if (!card) {
            return;
        }
        card.dataset.hasAnswer = answered ? '1' : '0';
        const index = card.dataset.question;
        const mapButton = document.querySelector(`.sw-section-map [data-question-jump="${index}"]`);
        if (mapButton) {
            mapButton.classList.toggle('done', Boolean(answered));
        }
        updateProgressCount();
        refreshSubmitState();
    }

    function taskTimerStorageKey(rowId) {
        return `toeic_sw_task_deadline:${cfg.testSession || 'session'}:${rowId}`;
    }

    function formatTaskSeconds(seconds) {
        const safeSeconds = Math.max(0, Number(seconds) || 0);
        const minutes = Math.floor(safeSeconds / 60);
        const remainder = safeSeconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
    }

    function getStoredTaskDeadline(rowId) {
        try {
            return Number(window.sessionStorage.getItem(taskTimerStorageKey(rowId)) || 0);
        } catch (error) {
            return 0;
        }
    }

    function setStoredTaskDeadline(rowId, deadline) {
        try {
            window.sessionStorage.setItem(taskTimerStorageKey(rowId), String(deadline));
        } catch (error) {
            // Timer still works for the current page even if sessionStorage is unavailable.
        }
    }

    function completeWritingTaskTimer(card) {
        const rowId = card.dataset.rowId;
        const textarea = card.querySelector('textarea[data-row-id]');
        if (textarea) {
            textarea.disabled = true;
        }
        setStatus(rowId, 'Task time ended', 'saved');
        refreshSubmitState();
    }

    function startWritingTaskTimer(card) {
        if (cfg.section !== 'writing' || !card) {
            return;
        }

        const minutes = Number(card.dataset.taskMinutes || 0);
        if (!minutes || minutes <= 0) {
            return;
        }

        const rowId = rowKey(card.dataset.rowId);
        const timer = document.getElementById(`sw-task-timer-${rowId}`);
        if (!timer) {
            return;
        }

        let deadline = getStoredTaskDeadline(rowId);
        if (!deadline) {
            deadline = Date.now() + minutes * 60 * 1000;
            setStoredTaskDeadline(rowId, deadline);
        }

        const tick = () => {
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            timer.textContent = formatTaskSeconds(remaining);
            if (remaining <= 0) {
                if (taskTimerIntervals.has(rowId)) {
                    clearInterval(taskTimerIntervals.get(rowId));
                    taskTimerIntervals.delete(rowId);
                }
                completeWritingTaskTimer(card);
            }
        };

        tick();
        if (!taskTimerIntervals.has(rowId) && Date.now() < deadline) {
            taskTimerIntervals.set(rowId, setInterval(tick, 1000));
        }
    }

    function trackTextSave(rowId, promise) {
        const key = rowKey(rowId);
        pendingTextSaves.set(key, promise);
        promise.then(() => {}, () => {}).finally(() => {
            if (pendingTextSaves.get(key) === promise) {
                pendingTextSaves.delete(key);
            }
        });
        return promise;
    }

    window.persistToeicSwAnswer = async function persistToeicSwAnswer(rowId, answer) {
        const key = rowKey(rowId);
        setStatus(key, 'Saving...', 'pending');
        const promise = postJson('ajax_save_toeic_sw_answer.php', {
            test_session: cfg.testSession,
            section: cfg.section,
            question_row_id: key,
            answer: answer,
        });
        trackTextSave(key, promise);
        const data = await promise;
        setStatus(key, 'Saved', 'saved');
        markQuestionAnswered(key, String(answer || '').trim() !== '');
        return data;
    };

    function queueTextSave(textarea) {
        const rowId = textarea.dataset.rowId;
        setWordCount(textarea);
        markQuestionAnswered(rowId, textarea.value.trim() !== '');

        if (saveTimers.has(rowId)) {
            clearTimeout(saveTimers.get(rowId));
        }
        saveTimers.set(rowId, setTimeout(() => {
            saveTimers.delete(rowId);
            window.persistToeicSwAnswer(rowId, textarea.value).catch((error) => {
                setStatus(rowId, error.message || 'Save failed', 'error');
            });
        }, 650));
    }

    async function flushTextAnswers() {
        document.querySelectorAll('textarea[data-row-id]').forEach((textarea) => {
            const rowId = textarea.dataset.rowId;
            if (saveTimers.has(rowId)) {
                clearTimeout(saveTimers.get(rowId));
                saveTimers.delete(rowId);
            }
        });

        const previousSaves = Array.from(pendingTextSaves.values());
        if (previousSaves.length > 0) {
            await Promise.allSettled(previousSaves);
        }

        const finalSaves = Array.from(document.querySelectorAll('textarea[data-row-id]')).map((textarea) => {
            return window.persistToeicSwAnswer(textarea.dataset.rowId, textarea.value);
        });
        if (finalSaves.length === 0) {
            return;
        }

        const results = await Promise.allSettled(finalSaves);
        const failed = results.filter((result) => result.status === 'rejected');
        if (failed.length > 0) {
            const firstReason = failed[0].reason && failed[0].reason.message ? failed[0].reason.message : 'Save failed';
            throw new Error(`${failed.length} writing answer(s) failed to save. ${firstReason}`);
        }
    }
    window.flushToeicSwTextAnswers = flushTextAnswers;

    function getPreferredAudioMimeType() {
        const candidates = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/ogg',
            'audio/mp4',
        ];

        if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
            return '';
        }

        return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
    }

    function audioExtensionFromMime(mimeType) {
        if (String(mimeType).includes('ogg')) return 'ogg';
        if (String(mimeType).includes('mp4')) return 'mp4';
        if (String(mimeType).includes('mpeg')) return 'mp3';
        if (String(mimeType).includes('wav')) return 'wav';
        return 'webm';
    }

    function clearRecordingTimer(rowId) {
        const key = rowKey(rowId);
        if (recordingTimers.has(key)) {
            clearInterval(recordingTimers.get(key));
            recordingTimers.delete(key);
        }
    }

    function stopStream(rowId) {
        const key = rowKey(rowId);
        const stream = recordingStreams.get(key);
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            recordingStreams.delete(key);
        }
    }

    function stopRecorder(rowId) {
        const key = rowKey(rowId);
        const recorder = recorders.get(key);
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
    }

    async function uploadRecording(rowId, blob) {
        const key = rowKey(rowId);
        pendingUploads.set(key, true);
        uploadErrors.delete(key);
        setStatus(key, 'Uploading recording...', 'pending');
        refreshSubmitState();

        const form = new FormData();
        const extension = audioExtensionFromMime(blob.type || 'audio/webm');
        form.append('csrf_token', csrfToken);
        form.append('test_session', cfg.testSession);
        form.append('section', cfg.section);
        form.append('question_row_id', key);
        form.append('recording', blob, `toeic-sw-${key}.${extension}`);

        try {
            const response = await fetch('ajax_save_toeic_sw_recording.php', {
                method: 'POST',
                body: form,
            });
            const data = await response.json().catch(() => ({
                success: false,
                error: 'Invalid JSON response from recording upload',
            }));
            if (!response.ok || !data.success) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }

            const audio = document.getElementById(`sw-playback-${key}`);
            if (audio) {
                audio.src = URL.createObjectURL(blob);
                audio.hidden = false;
            }
            markQuestionAnswered(key, true);
            setStatus(key, 'Recording saved', 'saved');
            return data;
        } catch (error) {
            uploadErrors.set(key, error.message || 'Upload failed');
            markQuestionAnswered(key, false);
            setStatus(key, error.message || 'Upload failed', 'error');
            allowAutoFlowRetry(key);
            throw error;
        } finally {
            pendingUploads.delete(key);
            refreshSubmitState();
        }
    }

    function setRecordControl(rowId, html) {
        const key = rowKey(rowId);
        const control = document.getElementById(`record-btn-${key}`);
        if (!control) {
            return;
        }
        control.innerHTML = html;
        control.setAttribute('aria-disabled', 'true');
        if ('disabled' in control) {
            control.disabled = true;
        }
        control.onclick = null;
    }

    function startToeicSwAutoRecordGap(rowId, maxSeconds) {
        const key = rowKey(rowId);
        const timer = document.getElementById(`sw-record-timer-${key}`);
        let remaining = AUTO_RECORD_GAP_SECONDS;

        setRecordControl(key, '<i class="fas fa-clock"></i> Recording cue');
        setStatus(key, `Recording starts in ${remaining}s`, 'pending');
        if (timer) {
            timer.textContent = `Recording starts in ${remaining}s`;
        }
        refreshSubmitState();

        const interval = setInterval(() => {
            remaining -= 1;
            if (remaining > 0) {
                if (timer) {
                    timer.textContent = `Recording starts in ${remaining}s`;
                }
                setStatus(key, `Recording starts in ${remaining}s`, 'pending');
                return;
            }

            clearInterval(interval);
            prepareTimers.delete(key);
            if (timer) {
                timer.textContent = 'Recording now';
            }
            window.startToeicSwTimedRecording(key, maxSeconds);
        }, 1000);

        prepareTimers.set(key, interval);
        refreshSubmitState();
    }

    function startActiveSpeakingFlow(card) {
        if (cfg.section !== 'speaking' || !card) {
            return;
        }

        const rowId = rowKey(card.dataset.rowId);
        if (card.dataset.hasAnswer === '1') {
            setRecordControl(rowId, '<i class="fas fa-check-circle"></i> Recording saved');
            const timer = document.getElementById(`sw-record-timer-${rowId}`);
            if (timer) {
                timer.textContent = 'Ready for next question';
            }
            if (!document.getElementById(`sw-status-${rowId}`)?.textContent) {
                setStatus(rowId, 'Recording saved', 'saved');
            }
            return;
        }

        if (autoStartedRows.has(rowId) || isSpeakingFlowBusy()) {
            return;
        }

        autoStartedRows.add(rowId);
        window.startToeicSwPrepare(
            rowId,
            Number(card.dataset.prepareSeconds || 0),
            Number(card.dataset.responseSeconds || 0)
        );
    }

    window.startToeicSwPrepare = function startToeicSwPrepare(rowId, prepareSeconds, maxSeconds) {
        const key = rowKey(rowId);
        if (prepareTimers.has(key) || recorders.has(key) || pendingUploads.has(key)) {
            return;
        }

        const timer = document.getElementById(`sw-record-timer-${key}`);
        let remaining = Math.max(0, Number(prepareSeconds || 0));

        const startGap = () => {
            prepareTimers.delete(key);
            startToeicSwAutoRecordGap(key, maxSeconds);
            refreshSubmitState();
        };

        if (remaining <= 0) {
            startGap();
            return;
        }

        setRecordControl(key, '<i class="fas fa-hourglass-half"></i> Preparing');
        setStatus(key, 'Preparing...', 'pending');
        if (timer) {
            timer.textContent = `Prepare ${remaining}s`;
        }
        refreshSubmitState();

        const interval = setInterval(() => {
            remaining -= 1;
            if (timer) {
                timer.textContent = remaining > 0 ? `Prepare ${remaining}s` : 'Ready';
            }
            if (remaining <= 0) {
                clearInterval(interval);
                startGap();
            }
        }, 1000);
        prepareTimers.set(key, interval);
        refreshSubmitState();
    };

    window.startToeicSwTimedRecording = async function startToeicSwTimedRecording(rowId, maxSeconds) {
        const key = rowKey(rowId);
        const timer = document.getElementById(`sw-record-timer-${key}`);

        if (recorders.has(key)) {
            return;
        }

        if (recorders.size > 0) {
            setStatus(key, 'Stop the current recording before starting another', 'error');
            return;
        }

        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            setStatus(key, 'Microphone recording is not supported in this browser', 'error');
            return;
        }

        if (typeof MediaRecorder === 'undefined') {
            setStatus(key, 'MediaRecorder is not available in this browser', 'error');
            return;
        }

        let stream;
        let recorder;
        const mimeType = getPreferredAudioMimeType();
        const chunks = [];
        uploadErrors.delete(key);
        refreshSubmitState();

        try {
            micRequests.add(key);
            setRecordControl(key, '<i class="fas fa-spinner fa-spin"></i> Opening mic');
            setStatus(key, 'Requesting microphone permission...', 'pending');
            refreshSubmitState();
            stream = await navigator.mediaDevices.getUserMedia({audio: true, video: false});
            try {
                recorder = mimeType ? new MediaRecorder(stream, {mimeType}) : new MediaRecorder(stream);
            } catch (error) {
                recorder = new MediaRecorder(stream);
            }
        } catch (error) {
            micRequests.delete(key);
            stopStream(key);
            if (stream) {
                stream.getTracks().forEach((track) => track.stop());
            }
            setRecordControl(key, '<i class="fas fa-triangle-exclamation"></i> Mic blocked');
            const blocked = error && (error.name === 'NotAllowedError' || error.name === 'SecurityError');
            setStatus(key, blocked ? 'Please allow microphone access for this site' : 'Could not access microphone', 'error');
            allowAutoFlowRetry(key);
            refreshSubmitState();
            return;
        }

        micRequests.delete(key);
        recorders.set(key, recorder);
        recordingStreams.set(key, stream);
        refreshSubmitState();

        recorder.ondataavailable = (event) => {
            if (event.data && event.data.size > 0) {
                chunks.push(event.data);
            }
        };

        recorder.onerror = () => {
            setStatus(key, 'Recording failed. Open this question again to retry automatically.', 'error');
            allowAutoFlowRetry(key);
            stopRecorder(key);
        };

        recorder.onstop = () => {
            clearRecordingTimer(key);
            stopStream(key);
            recorders.delete(key);
            refreshSubmitState();
            const recordedMime = recorder.mimeType || (chunks[0] && chunks[0].type) || mimeType || 'audio/webm';

            if (timer) {
                timer.textContent = '';
            }

            if (chunks.length === 0) {
                setRecordControl(key, '<i class="fas fa-circle-exclamation"></i> No audio captured');
                setStatus(key, 'No audio was captured. Open this question again to retry automatically.', 'error');
                allowAutoFlowRetry(key);
                refreshSubmitState();
                return;
            }

            const blob = new Blob(chunks, {type: recordedMime});
            setRecordControl(key, '<i class="fas fa-cloud-upload-alt"></i> Uploading');

            uploadRecording(key, blob)
                .catch((error) => {
                    console.error('TOEIC SW recording upload failed:', error);
                })
                .finally(() => {
                    const card = document.querySelector(`.sw-question[data-row-id="${key}"]`);
                    if (card && card.dataset.hasAnswer === '1') {
                        setRecordControl(key, '<i class="fas fa-check-circle"></i> Recording saved');
                    } else {
                        setRecordControl(key, '<i class="fas fa-circle-exclamation"></i> Upload failed');
                    }
                });
        };

        let remaining = Math.max(1, Number(maxSeconds || 30));
        setRecordControl(key, '<i class="fas fa-microphone-lines"></i> Recording');
        if (timer) {
            timer.textContent = `${remaining}s`;
        }
        setStatus(key, 'Recording...', 'pending');
        recorder.start();
        refreshSubmitState();

        const interval = setInterval(() => {
            if (!recorders.has(key)) {
                clearRecordingTimer(key);
                return;
            }
            remaining -= 1;
            if (timer) {
                timer.textContent = remaining > 0 ? `${remaining}s` : 'Done';
            }
            if (remaining <= 0) {
                stopRecorder(key);
            }
        }, 1000);
        recordingTimers.set(key, interval);
    };

    window.waitForToeicSwRecordingSaves = async function waitForToeicSwRecordingSaves() {
        const activeRecorders = Array.from(recorders.values()).filter((recorder) => recorder && recorder.state === 'recording');
        if (activeRecorders.length > 0) {
            throw new Error('A recording is still in progress. Stop it before submitting.');
        }

        while (pendingUploads.size > 0) {
            await new Promise((resolve) => setTimeout(resolve, 250));
        }

        if (uploadErrors.size > 0) {
            const firstError = Array.from(uploadErrors.values())[0];
            throw new Error(firstError || 'A recording upload failed. Open that question again to retry automatically.');
        }
    };

    window.showToeicSwQuestion = function showToeicSwQuestion(index, options) {
        options = options || {};
        const cards = questionCards();
        if (cards.length === 0) {
            return;
        }
        const nextIndex = Math.max(0, Math.min(Number(index) || 0, cards.length - 1));
        if (!options.force && nextIndex !== currentQuestion && cfg.section === 'speaking' && isSpeakingFlowBusy()) {
            refreshSubmitState();
            setSubmitMessage('Auto prepare, recording, or upload is still running. Next unlocks after save.', 'error');
            return;
        }

        cards.forEach((card, cardIndex) => {
            const active = cardIndex === nextIndex;
            card.hidden = !active;
            card.classList.toggle('active', active);
        });

        currentQuestion = nextIndex;
        document.querySelectorAll('.sw-section-map [data-question-jump]').forEach((button) => {
            button.classList.toggle('active', Number(button.dataset.questionJump) === nextIndex);
        });

        const current = document.getElementById('sw-current-question');
        if (current) {
            current.textContent = String(nextIndex + 1);
        }
        const prev = document.getElementById('sw-prev-question');
        const next = document.getElementById('sw-next-question');
        if (prev) {
            prev.disabled = nextIndex === 0;
        }
        if (next) {
            next.disabled = nextIndex >= cards.length - 1;
        }

        const activeCard = cards[nextIndex];
        startWritingTaskTimer(activeCard);
        if (activeCard && options.scroll !== false) {
            activeCard.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
        refreshSubmitState();
        startActiveSpeakingFlow(activeCard);
    };

    window.prevToeicSwQuestion = function prevToeicSwQuestion() {
        window.showToeicSwQuestion(currentQuestion - 1);
    };

    window.nextToeicSwQuestion = function nextToeicSwQuestion() {
        window.showToeicSwQuestion(currentQuestion + 1);
    };

    window.submitToeicSwSection = async function submitToeicSwSection() {
        if (submitting) {
            return;
        }

        const submit = document.getElementById('sw-submit-section');
        submitting = true;
        setSubmitMessage('', '');
        setOverlay(true);
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Submitting...';
        }

        try {
            await flushTextAnswers();
            await window.waitForToeicSwRecordingSaves();

            if (cfg.section === 'speaking') {
                const missing = Array.from(document.querySelectorAll('.sw-question[data-section="speaking"][data-has-answer="0"]'));
                if (missing.length > 0) {
                    const firstMissing = missing[0];
                    window.showToeicSwQuestion(Number(firstMissing.dataset.question || 0), {force: true});
                    throw new Error(`Complete and upload all speaking recordings before submitting. Missing: ${missing.length}`);
                }
            }

            const data = await postJson('ajax_submit_section_toeic_sw.php', {
                test_session: cfg.testSession,
                section: cfg.section,
            });
            window.location.href = data.redirect || 'index.php';
        } catch (error) {
            setOverlay(false);
            submitting = false;
            if (submit) {
                submit.textContent = cfg.section === 'speaking' ? 'Submit Speaking' : 'Submit Writing';
            }
            refreshSubmitState();
            setSubmitMessage(error.message || 'Submit failed', 'error');
        }
    };

    function startSectionTimer() {
        const timer = document.getElementById('sw-section-timer');
        if (!timer || !cfg.sectionDeadline) {
            return;
        }

        let interval = null;
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
        interval = setInterval(tick, 1000);
        tick();
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('textarea[data-row-id]').forEach((textarea) => {
            setWordCount(textarea);
            textarea.addEventListener('input', () => queueTextSave(textarea));
        });
        window.showToeicSwQuestion(0, {force: true, scroll: false});
        updateProgressCount();
        refreshSubmitState();
        startSectionTimer();
    });
})();
